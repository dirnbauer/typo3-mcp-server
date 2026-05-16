<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\BatchedRecordPositioningService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Analyze raw content and propose TYPO3 content elements.
 *
 * Dynamically discovers ALL available CTypes and their field capabilities
 * from TCA to find the best match for each content section. No hardcoded
 * CType knowledge — works with core, extensions, and custom content types.
 *
 * @phpstan-type ContentElement array<string, mixed>
 * @phpstan-type ParsedSection array{type: string, content: string, level: int, raw: string}
 * @phpstan-type CTypeProfile array{label: string, hasBodytext: bool, hasHeader: bool, hasImage: bool, hasAssets: bool, fields: list<string>}
 */
final class ImportContentTool extends AbstractRecordTool
{
    private const FORMAT_AUTO = 'auto';
    private const FORMAT_MARKDOWN = 'markdown';
    private const FORMAT_HTML = 'html';
    private const FORMAT_TEXT = 'text';

    private const MODE_ANALYZE = 'analyze';
    private const MODE_EXECUTE = 'execute';

    /**
     * Section types produced by the parsers.
     * Each maps to a set of field requirements used for CType scoring.
     */
    private const SECTION_NEEDS = [
        // type => [needs_bodytext, needs_header_only, needs_image, prefers_raw_html]
        'heading' => [false, true, false, false],
        'text' => [true, false, false, false],
        'list' => [true, false, false, false],
        'html' => [true, false, false, true],
        'table' => [true, false, false, true],
        'code' => [true, false, false, true],
        'image' => [false, false, true, false],
    ];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly BatchedRecordPositioningService $batchedRecordPositioningService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Analyze raw content (text, Markdown, or HTML) and map it to TYPO3 content elements. '
                . 'Dynamically discovers ALL available CTypes from TCA — core, extensions, and custom content blocks. '
                . 'In "analyze" mode (default): returns a proposal as JSON for review. '
                . 'In "execute" mode: creates the page content elements directly via DataHandler. '
                . 'The chatbot can paste content and this tool chooses the best content elements automatically.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'Raw content to import: plain text, Markdown, or HTML',
                    ],
                    'targetPid' => [
                        'type' => 'integer',
                        'description' => 'Page ID where elements will be created',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'analyze = propose elements (read-only, default). '
                            . 'execute = create elements directly on the page via DataHandler.',
                        'enum' => [self::MODE_ANALYZE, self::MODE_EXECUTE],
                        'default' => self::MODE_ANALYZE,
                    ],
                    'format' => [
                        'type' => 'string',
                        'description' => 'Content format hint: auto (detect), markdown, html, or text. Default: auto',
                        'enum' => [self::FORMAT_AUTO, self::FORMAT_MARKDOWN, self::FORMAT_HTML, self::FORMAT_TEXT],
                        'default' => self::FORMAT_AUTO,
                    ],
                    'colPos' => [
                        'type' => 'integer',
                        'description' => 'Column position for content elements, default: 0 (main content area)',
                        'default' => 0,
                    ],
                ],
                'required' => ['content', 'targetPid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $content = is_string($params['content'] ?? null) ? $params['content'] : '';
        $targetPid = is_numeric($params['targetPid'] ?? null) ? (int)$params['targetPid'] : 0;
        $format = is_string($params['format'] ?? null) ? $params['format'] : self::FORMAT_AUTO;
        $colPos = is_numeric($params['colPos'] ?? null) ? (int)$params['colPos'] : 0;
        $mode = is_string($params['mode'] ?? null) ? $params['mode'] : self::MODE_ANALYZE;

        if (trim($content) === '') {
            throw new ValidationException(['content must not be empty']);
        }
        if ($targetPid < 0) {
            throw new ValidationException(['targetPid must be a non-negative integer']);
        }
        if (!in_array($mode, [self::MODE_ANALYZE, self::MODE_EXECUTE], true)) {
            throw new ValidationException(['mode must be "analyze" or "execute"']);
        }

        $this->ensureTableAccess('tt_content', 'write');

        if ($format === self::FORMAT_AUTO) {
            $format = $this->detectFormat($content);
        }

        // Build CType profiles from TCA — discover ALL available types and their fields
        $availableTypes = $this->tableAccessService->getAvailableTypes('tt_content');
        $ctypeProfiles = $this->buildCTypeProfiles($availableTypes);

        // Parse content into sections
        $sections = match ($format) {
            self::FORMAT_HTML => $this->parseHtml($content),
            self::FORMAT_MARKDOWN => $this->parseMarkdown($content),
            default => $this->parsePlainText($content),
        };

        $sections = $this->mergeConsecutiveSections($sections);

        // Map sections to content elements using dynamic scoring
        $elements = [];
        foreach ($sections as $index => $section) {
            $elements[] = $this->mapSectionToElement($section, $index, $ctypeProfiles);
        }

        // Execute mode: create elements via DataHandler
        if ($mode === self::MODE_EXECUTE) {
            return $this->executeCreation($elements, $targetPid, $colPos, $format);
        }

        // Analyze mode: return proposal
        $typeSummary = [];
        foreach ($ctypeProfiles as $ctype => $profile) {
            $typeSummary[$ctype] = [
                'label' => $profile['label'],
                'fields' => $profile['fields'],
            ];
        }

        $result = [
            'targetPid' => $targetPid,
            'format' => $format,
            'colPos' => $colPos,
            'mode' => self::MODE_ANALYZE,
            'availableContentTypes' => $typeSummary,
            'elements' => $elements,
            'totalElements' => count($elements),
            'hint' => 'Review the proposed elements. You can change CType to any type listed in '
                . 'availableContentTypes. Then either call ImportContent again with mode=execute, '
                . 'or call BulkWrite with action=create for each element '
                . 'on table tt_content with pid=' . $targetPid . ' and colPos=' . $colPos . '.',
        ];

        return $this->createJsonResult($result);
    }

    /**
     * Create all proposed elements via DataHandler in a single transaction.
     *
     * @param list<ContentElement> $elements
     */
    private function executeCreation(array $elements, int $targetPid, int $colPos, string $format): CallToolResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        if ($elements === []) {
            return $this->createJsonResult([
                'mode' => self::MODE_EXECUTE,
                'targetPid' => $targetPid,
                'message' => 'No content elements to create — input was empty after parsing.',
                'created' => [],
            ]);
        }

        // Build DataHandler dataMap
        /** @var array<string, array<string, array<string, mixed>>> $dataMap */
        $dataMap = [];

        foreach ($elements as $index => $element) {
            $newId = 'NEW_import_' . $index;
            $record = [
                'pid' => $targetPid,
                'CType' => $element['CType'],
                'colPos' => $colPos,
            ];

            if (!empty($element['header'])) {
                $record['header'] = $element['header'];
            }
            if (!empty($element['bodytext'])) {
                $record['bodytext'] = $element['bodytext'];
            }
            if (isset($element['header_layout']) && $element['header_layout'] > 0) {
                $record['header_layout'] = $element['header_layout'];
            }

            $dataMap['tt_content'][$newId] = $record;
        }

        $dataMap = $this->batchedRecordPositioningService->assignAppendPositions($dataMap);

        // Execute
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        // Collect results
        $created = [];
        $errorCount = 0;
        foreach ($elements as $index => $element) {
            $newId = 'NEW_import_' . $index;
            $substUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            if (is_numeric($substUid) && (int)$substUid > 0) {
                $created[] = [
                    'index' => $index,
                    'uid' => (int)$substUid,
                    'CType' => $element['CType'],
                    'summary' => $element['summary'],
                ];
            } else {
                $created[] = [
                    'index' => $index,
                    'error' => 'Creation failed for element #' . $index . ' (' . (is_string($element['CType'] ?? null) ? $element['CType'] : 'unknown') . ')',
                ];
                $errorCount++;
            }
        }

        // Collect global errors
        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = is_scalar($error) ? (string)$error : 'Unknown error';
        }

        $result = [
            'mode' => self::MODE_EXECUTE,
            'targetPid' => $targetPid,
            'format' => $format,
            'colPos' => $colPos,
            'totalCreated' => count($elements) - $errorCount,
            'totalErrors' => $errorCount,
            'created' => $created,
        ];

        if ($errors !== []) {
            $result['errors'] = $errors;
        }

        return $this->createJsonResult($result);
    }

    /**
     * Build field profiles for all available CTypes by querying TCA.
     *
     * @param array<string, string> $availableTypes CType value → label
     * @return array<string, CTypeProfile>
     */
    private function buildCTypeProfiles(array $availableTypes): array
    {
        $profiles = [];

        foreach ($availableTypes as $ctype => $label) {
            if ($ctype === '--div--' || $ctype === '') {
                continue;
            }

            $fields = $this->tableAccessService->getAvailableFields('tt_content', $ctype);
            $fieldNames = array_keys($fields);

            $profiles[$ctype] = [
                'label' => TableAccessService::translateLabel($label),
                'hasBodytext' => in_array('bodytext', $fieldNames, true),
                'hasHeader' => in_array('header', $fieldNames, true),
                'hasImage' => in_array('image', $fieldNames, true),
                'hasAssets' => in_array('assets', $fieldNames, true),
                'fields' => $fieldNames,
            ];
        }

        return $profiles;
    }

    /**
     * Score and select the best CType for a section using TCA field profiles.
     *
     * @param ParsedSection $section
     * @param array<string, CTypeProfile> $profiles
     * @return ContentElement
     */
    private function mapSectionToElement(array $section, int $index, array $profiles): array
    {
        $sectionType = $section['type'];
        $needs = self::SECTION_NEEDS[$sectionType] ?? [true, false, false, false];
        [$needsBodytext, $needsHeaderOnly, $needsImage, $prefersRawHtml] = $needs;

        // Score each CType
        $bestCType = '';
        $bestScore = -1;

        foreach ($profiles as $ctype => $profile) {
            $score = $this->scoreCType($ctype, $profile, $needsBodytext, $needsHeaderOnly, $needsImage, $prefersRawHtml);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCType = $ctype;
            }
        }

        // Fallback to first available
        if ($bestCType === '') {
            $bestCType = array_key_first($profiles) ?? 'text';
        }

        $element = [
            'index' => $index,
            'CType' => $bestCType,
            'header' => '',
            'bodytext' => '',
            'header_layout' => 0,
            'summary' => '',
        ];

        $label = $profiles[$bestCType]['label'] ?? $bestCType;

        switch ($sectionType) {
            case 'heading':
                $element['header'] = $section['content'];
                $element['header_layout'] = $section['level'];
                $element['summary'] = 'H' . $section['level'] . ' heading → ' . $label;
                break;

            case 'text':
                $paragraphCount = substr_count($section['content'], "\n\n") + 1;
                $element['bodytext'] = $section['content'];
                $element['summary'] = ($paragraphCount > 1 ? $paragraphCount . ' paragraphs' : 'Text') . ' → ' . $label;
                break;

            case 'list':
                $element['bodytext'] = $section['content'];
                $itemCount = preg_match_all('/^\s*[-*+]\s|^\s*\d+\.\s/m', $section['content']);
                $element['summary'] = $itemCount . ' list items → ' . $label;
                break;

            case 'html':
            case 'table':
            case 'code':
                $element['bodytext'] = $section['raw'];
                $typeLabel = match ($sectionType) {
                    'table' => 'HTML table',
                    'code' => 'Code block',
                    default => 'HTML content',
                };
                $element['summary'] = $typeLabel . ' → ' . $label;
                break;

            case 'image':
                $element['header'] = $section['content'];
                $element['summary'] = 'Image reference → ' . $label . ' (attach file via WriteTable)';
                break;

            default:
                $element['bodytext'] = $section['content'];
                $element['summary'] = 'Content → ' . $label;
                break;
        }

        return $element;
    }

    /**
     * Score how well a CType fits the section's needs.
     *
     * @param CTypeProfile $profile
     */
    private function scoreCType(
        string $ctype,
        array $profile,
        bool $needsBodytext,
        bool $needsHeaderOnly,
        bool $needsImage,
        bool $prefersRawHtml,
    ): int {
        $score = 0;

        if ($needsHeaderOnly) {
            // Heading sections: prefer CTypes that are header-focused
            // A CType with header but NO bodytext is ideal (pure heading element)
            if ($profile['hasHeader'] && !$profile['hasBodytext']) {
                $score += 100;
            } elseif ($profile['hasHeader']) {
                $score += 50;
            }
            // Penalize CTypes with many unrelated fields (overly complex for a heading)
            $score -= max(0, count($profile['fields']) - 5);
        } elseif ($needsImage) {
            // Image sections: prefer CTypes with image/assets fields
            if ($profile['hasImage'] || $profile['hasAssets']) {
                $score += 100;
            }
            // Prefer types without bodytext (pure image)
            if (!$profile['hasBodytext']) {
                $score += 20;
            }
        } elseif ($needsBodytext) {
            // Text/list/html sections: must have bodytext
            if (!$profile['hasBodytext']) {
                return -100; // Disqualify
            }
            $score += 50;

            if ($prefersRawHtml) {
                // HTML/table/code: prefer simple CTypes (fewer unrelated fields)
                // The "html" CType name is a strong signal
                if ($ctype === 'html') {
                    $score += 80;
                }
                // Prefer fewer fields (simpler = better for raw HTML)
                $score -= max(0, count($profile['fields']) - 3);
            } else {
                // Regular text: prefer CTypes designed for text content
                if ($profile['hasHeader']) {
                    $score += 20; // Bonus: can set a header on the text block
                }
                // Slight preference for simpler text types
                if ($ctype === 'text') {
                    $score += 30;
                }
                // textmedia/textpic are good for text too (just heavier)
                if ($profile['hasImage'] || $profile['hasAssets']) {
                    $score += 5;
                }
            }
        }

        return $score;
    }

    // -----------------------------------------------------------------------
    // Format detection
    // -----------------------------------------------------------------------

    private function detectFormat(string $content): string
    {
        if (preg_match('/<(?:h[1-6]|p|div|table|ul|ol|pre|blockquote|img|hr)\b/i', $content)) {
            return self::FORMAT_HTML;
        }

        if (preg_match('/^#{1,6}\s/m', $content)
            || preg_match('/^```/m', $content)
            || preg_match('/!\[.*?\]\(.*?\)/', $content)
            || preg_match('/^\s*[-*+]\s/m', $content)
        ) {
            return self::FORMAT_MARKDOWN;
        }

        return self::FORMAT_TEXT;
    }

    // -----------------------------------------------------------------------
    // Parsers — split content into typed sections
    // -----------------------------------------------------------------------

    /**
     * @return list<ParsedSection>
     */
    private function parseHtml(string $content): array
    {
        $sections = [];
        $content = trim($content);

        $pattern = '/(<(?:h[1-6]|table|pre|ul|ol|blockquote|hr)[^>]*>.*?<\/(?:h[1-6]|table|pre|ul|ol|blockquote)>|<hr\s*\/?>|<img\s[^>]*\/?>)/is';

        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            $parts = [$content];
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match('/^<h([1-6])[^>]*>(.*?)<\/h\1>$/is', $part, $m)) {
                $sections[] = ['type' => 'heading', 'content' => strip_tags($m[2]), 'level' => (int)$m[1], 'raw' => $part];
                continue;
            }
            if (preg_match('/^<table\b/i', $part)) {
                $sections[] = ['type' => 'table', 'content' => $part, 'level' => 0, 'raw' => $part];
                continue;
            }
            if (preg_match('/^<pre\b/i', $part)) {
                $sections[] = ['type' => 'code', 'content' => $part, 'level' => 0, 'raw' => $part];
                continue;
            }
            if (preg_match('/^<[uo]l\b/i', $part)) {
                $sections[] = ['type' => 'list', 'content' => $part, 'level' => 0, 'raw' => $part];
                continue;
            }
            if (preg_match('/^<hr\b/i', $part)) {
                continue;
            }
            if (preg_match('/^<img\b/i', $part)) {
                $alt = '';
                if (preg_match('/alt=["\']([^"\']*)["\']/', $part, $am)) {
                    $alt = $am[1];
                }
                $sections[] = ['type' => 'image', 'content' => $alt, 'level' => 0, 'raw' => $part];
                continue;
            }

            $textContent = preg_replace('/<\/?p[^>]*>/i', '', $part);
            $textContent = is_string($textContent) ? trim($textContent) : trim($part);
            if ($textContent !== '') {
                $sections[] = ['type' => 'text', 'content' => $textContent, 'level' => 0, 'raw' => $part];
            }
        }

        return $sections;
    }

    /**
     * @return list<ParsedSection>
     */
    private function parseMarkdown(string $content): array
    {
        $sections = [];
        $lines = explode("\n", $content);
        $currentBlock = '';
        $currentType = 'text';
        $inCodeFence = false;
        $codeContent = '';

        $flushBlock = function () use (&$sections, &$currentBlock, &$currentType): void {
            $trimmed = trim($currentBlock);
            if ($trimmed !== '') {
                $sections[] = ['type' => $currentType, 'content' => $trimmed, 'level' => 0, 'raw' => $trimmed];
            }
            $currentBlock = '';
            $currentType = 'text';
        };

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if ($inCodeFence) {
                    $inCodeFence = false;
                    $sections[] = ['type' => 'code', 'content' => trim($codeContent), 'level' => 0, 'raw' => $codeContent];
                    $codeContent = '';
                    continue;
                }
                $flushBlock();
                $inCodeFence = true;
                $codeContent = '';
                continue;
            }

            if ($inCodeFence) {
                $codeContent .= $line . "\n";
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $flushBlock();
                $sections[] = ['type' => 'heading', 'content' => trim($m[2]), 'level' => strlen($m[1]), 'raw' => $line];
                continue;
            }

            if (preg_match('/^[-*_]{3,}\s*$/', $line)) {
                $flushBlock();
                continue;
            }

            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $line, $m)) {
                $flushBlock();
                $sections[] = ['type' => 'image', 'content' => $m[1] !== '' ? $m[1] : $m[2], 'level' => 0, 'raw' => $line];
                continue;
            }

            if (trim($line) === '') {
                if (trim($currentBlock) !== '') {
                    $flushBlock();
                }
                continue;
            }

            if (preg_match('/^\s*[-*+]\s/', $line) || preg_match('/^\s*\d+\.\s/', $line)) {
                if ($currentType !== 'list') {
                    $flushBlock();
                    $currentType = 'list';
                }
                $currentBlock .= $line . "\n";
                continue;
            }

            if ($currentType === 'list') {
                $flushBlock();
            }
            $currentType = 'text';
            $currentBlock .= $line . "\n";
        }

        if ($inCodeFence && trim($codeContent) !== '') {
            $sections[] = ['type' => 'code', 'content' => trim($codeContent), 'level' => 0, 'raw' => $codeContent];
        } else {
            $flushBlock();
        }

        return $sections;
    }

    /**
     * @return list<ParsedSection>
     */
    private function parsePlainText(string $content): array
    {
        $sections = [];
        $paragraphs = preg_split('/\n{2,}/', $content);
        if (!is_array($paragraphs)) {
            $paragraphs = [$content];
        }

        foreach ($paragraphs as $i => $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $isHeading = $i === 0
                && mb_strlen($paragraph) < 80
                && !str_contains($paragraph, "\n")
                && !str_ends_with($paragraph, '.');

            if ($isHeading) {
                $sections[] = ['type' => 'heading', 'content' => $paragraph, 'level' => 1, 'raw' => $paragraph];
            } else {
                $sections[] = ['type' => 'text', 'content' => $paragraph, 'level' => 0, 'raw' => $paragraph];
            }
        }

        return $sections;
    }

    // -----------------------------------------------------------------------
    // Section merging
    // -----------------------------------------------------------------------

    /**
     * @param list<ParsedSection> $sections
     * @return list<ParsedSection>
     */
    private function mergeConsecutiveSections(array $sections): array
    {
        if (count($sections) < 2) {
            return $sections;
        }

        $merged = [];
        $current = $sections[0];

        for ($i = 1, $len = count($sections); $i < $len; $i++) {
            $next = $sections[$i];

            if ($current['type'] === $next['type'] && in_array($current['type'], ['text', 'list'], true)) {
                $current['content'] .= "\n\n" . $next['content'];
                $current['raw'] .= "\n\n" . $next['raw'];
            } else {
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;

        return $merged;
    }
}
