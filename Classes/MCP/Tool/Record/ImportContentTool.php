<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;

/**
 * Analyze raw content and propose TYPO3 content elements.
 *
 * Accepts plain text, Markdown, or HTML. Splits the content into logical
 * sections, maps each to the best-fitting CType from what's actually
 * available, and returns a proposal as JSON. The chatbot reviews/adjusts,
 * then calls BulkWrite to create all elements.
 *
 * @phpstan-type ContentElement array{index: int, CType: string, header: string, bodytext: string, header_layout: int, summary: string}
 * @phpstan-type ParsedSection array{type: string, content: string, level: int, raw: string}
 */
final class ImportContentTool extends AbstractRecordTool
{
    private const FORMAT_AUTO = 'auto';
    private const FORMAT_MARKDOWN = 'markdown';
    private const FORMAT_HTML = 'html';
    private const FORMAT_TEXT = 'text';

    /** @var array<string, string> CType preference order: section type → preferred CType */
    private const CTYPE_MAP = [
        'heading' => 'header',
        'text' => 'text',
        'richtext' => 'textmedia',
        'html' => 'html',
        'image' => 'image',
        'code' => 'html',
        'table' => 'html',
        'list' => 'text',
    ];

    /** @var array<string, list<string>> Fallback chain when preferred CType is unavailable */
    private const CTYPE_FALLBACKS = [
        'header' => ['text', 'textmedia'],
        'text' => ['textmedia', 'textpic'],
        'textmedia' => ['textpic', 'text'],
        'html' => ['text'],
        'image' => ['textmedia', 'textpic'],
    ];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Analyze raw content (text, Markdown, or HTML) and propose TYPO3 content elements. '
                . 'Splits content into logical sections (headings, paragraphs, tables, code blocks) and maps each '
                . 'to the best-fitting CType from what is actually available. Returns a proposal as JSON — '
                . 'review and adjust the elements, then call BulkWrite to create them all at once. '
                . 'This tool does NOT create records; it only proposes a structure.',
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
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $content = \is_string($params['content'] ?? null) ? $params['content'] : '';
        $targetPid = is_numeric($params['targetPid'] ?? null) ? (int)$params['targetPid'] : 0;
        $format = \is_string($params['format'] ?? null) ? $params['format'] : self::FORMAT_AUTO;
        $colPos = is_numeric($params['colPos'] ?? null) ? (int)$params['colPos'] : 0;

        if (trim($content) === '') {
            throw new ValidationException(['content must not be empty']);
        }
        if ($targetPid < 0) {
            throw new ValidationException(['targetPid must be a non-negative integer']);
        }

        // Validate table access
        $this->ensureTableAccess('tt_content', 'write');

        // Detect format
        if ($format === self::FORMAT_AUTO) {
            $format = $this->detectFormat($content);
        }

        // Get available CTypes
        $availableTypes = $this->tableAccessService->getAvailableTypes('tt_content');
        $availableCTypes = array_keys($availableTypes);

        // Parse content into sections
        $sections = match ($format) {
            self::FORMAT_HTML => $this->parseHtml($content),
            self::FORMAT_MARKDOWN => $this->parseMarkdown($content),
            default => $this->parsePlainText($content),
        };

        // Merge consecutive same-type sections
        $sections = $this->mergeConsecutiveSections($sections);

        // Map sections to content elements
        $elements = [];
        foreach ($sections as $index => $section) {
            $elements[] = $this->mapSectionToElement($section, $index, $availableCTypes);
        }

        $result = [
            'targetPid' => $targetPid,
            'format' => $format,
            'colPos' => $colPos,
            'availableCTypes' => $availableCTypes,
            'elements' => $elements,
            'totalElements' => \count($elements),
            'hint' => 'Review the proposed elements. Adjust CTypes, headers, or content as needed, '
                . 'then call BulkWrite with action=create for each element on table tt_content with pid=' . $targetPid
                . ' and colPos=' . $colPos . '.',
        ];

        return $this->createJsonResult($result);
    }

    /**
     * Detect content format from content string.
     */
    private function detectFormat(string $content): string
    {
        // HTML: contains block-level HTML tags
        if (preg_match('/<(?:h[1-6]|p|div|table|ul|ol|pre|blockquote|img|hr)\b/i', $content)) {
            return self::FORMAT_HTML;
        }

        // Markdown: contains heading markers, code fences, or image syntax
        if (preg_match('/^#{1,6}\s/m', $content)
            || preg_match('/^```/m', $content)
            || preg_match('/!\[.*?\]\(.*?\)/', $content)
            || preg_match('/^\s*[-*+]\s/m', $content)
        ) {
            return self::FORMAT_MARKDOWN;
        }

        return self::FORMAT_TEXT;
    }

    /**
     * Parse HTML content into sections.
     *
     * @return list<ParsedSection>
     */
    private function parseHtml(string $content): array
    {
        $sections = [];

        // Normalize whitespace around block elements
        $content = trim($content);

        // Split on block-level boundaries
        // Match: headings, hr, table, pre, ul, ol, img (self-closing), div, blockquote
        $pattern = '/(<(?:h[1-6]|table|pre|ul|ol|blockquote|hr)[^>]*>.*?<\/(?:h[1-6]|table|pre|ul|ol|blockquote)>|<hr\s*\/?>|<img\s[^>]*\/?>)/is';

        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!\is_array($parts)) {
            $parts = [$content];
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // Heading
            if (preg_match('/^<h([1-6])[^>]*>(.*?)<\/h\1>$/is', $part, $m)) {
                $sections[] = [
                    'type' => 'heading',
                    'content' => strip_tags($m[2]),
                    'level' => (int)$m[1],
                    'raw' => $part,
                ];
                continue;
            }

            // Table
            if (preg_match('/^<table\b/i', $part)) {
                $sections[] = [
                    'type' => 'table',
                    'content' => $part,
                    'level' => 0,
                    'raw' => $part,
                ];
                continue;
            }

            // Pre/code block
            if (preg_match('/^<pre\b/i', $part)) {
                $sections[] = [
                    'type' => 'code',
                    'content' => $part,
                    'level' => 0,
                    'raw' => $part,
                ];
                continue;
            }

            // List (ul/ol)
            if (preg_match('/^<[uo]l\b/i', $part)) {
                $sections[] = [
                    'type' => 'list',
                    'content' => $part,
                    'level' => 0,
                    'raw' => $part,
                ];
                continue;
            }

            // HR → section separator, skip
            if (preg_match('/^<hr\b/i', $part)) {
                continue;
            }

            // Image
            if (preg_match('/^<img\b/i', $part)) {
                $alt = '';
                if (preg_match('/alt=["\']([^"\']*)["\']/', $part, $am)) {
                    $alt = $am[1];
                }
                $sections[] = [
                    'type' => 'image',
                    'content' => $alt,
                    'level' => 0,
                    'raw' => $part,
                ];
                continue;
            }

            // Everything else is text (strip <p> wrappers for bodytext)
            $textContent = preg_replace('/<\/?p[^>]*>/i', '', $part);
            $textContent = \is_string($textContent) ? trim($textContent) : trim($part);
            if ($textContent !== '') {
                $sections[] = [
                    'type' => 'text',
                    'content' => $textContent,
                    'level' => 0,
                    'raw' => $part,
                ];
            }
        }

        return $sections;
    }

    /**
     * Parse Markdown content into sections.
     *
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
                $sections[] = [
                    'type' => $currentType,
                    'content' => $trimmed,
                    'level' => 0,
                    'raw' => $trimmed,
                ];
            }
            $currentBlock = '';
            $currentType = 'text';
        };

        foreach ($lines as $line) {
            // Code fence toggle
            if (preg_match('/^```/', $line)) {
                if ($inCodeFence) {
                    // End code fence
                    $inCodeFence = false;
                    $sections[] = [
                        'type' => 'code',
                        'content' => trim($codeContent),
                        'level' => 0,
                        'raw' => $codeContent,
                    ];
                    $codeContent = '';
                    continue;
                }
                // Start code fence
                $flushBlock();
                $inCodeFence = true;
                $codeContent = '';
                continue;
            }

            if ($inCodeFence) {
                $codeContent .= $line . "\n";
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $flushBlock();
                $sections[] = [
                    'type' => 'heading',
                    'content' => trim($m[2]),
                    'level' => \strlen($m[1]),
                    'raw' => $line,
                ];
                continue;
            }

            // Horizontal rule
            if (preg_match('/^[-*_]{3,}\s*$/', $line)) {
                $flushBlock();
                continue;
            }

            // Image reference (standalone line)
            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $line, $m)) {
                $flushBlock();
                $sections[] = [
                    'type' => 'image',
                    'content' => $m[1] !== '' ? $m[1] : $m[2],
                    'level' => 0,
                    'raw' => $line,
                ];
                continue;
            }

            // Empty line = paragraph break
            if (trim($line) === '') {
                if (trim($currentBlock) !== '') {
                    $flushBlock();
                }
                continue;
            }

            // List item
            if (preg_match('/^\s*[-*+]\s/', $line) || preg_match('/^\s*\d+\.\s/', $line)) {
                if ($currentType !== 'list') {
                    $flushBlock();
                    $currentType = 'list';
                }
                $currentBlock .= $line . "\n";
                continue;
            }

            // Regular text
            if ($currentType === 'list') {
                $flushBlock();
            }
            $currentType = 'text';
            $currentBlock .= $line . "\n";
        }

        // Flush remaining
        if ($inCodeFence && trim($codeContent) !== '') {
            $sections[] = [
                'type' => 'code',
                'content' => trim($codeContent),
                'level' => 0,
                'raw' => $codeContent,
            ];
        } else {
            $flushBlock();
        }

        return $sections;
    }

    /**
     * Parse plain text into sections.
     *
     * @return list<ParsedSection>
     */
    private function parsePlainText(string $content): array
    {
        $sections = [];
        $paragraphs = preg_split('/\n{2,}/', $content);
        if (!\is_array($paragraphs)) {
            $paragraphs = [$content];
        }

        foreach ($paragraphs as $i => $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // Heuristic: short line without period at start could be a heading
            $isHeading = $i === 0
                && mb_strlen($paragraph) < 80
                && !str_contains($paragraph, "\n")
                && !str_ends_with($paragraph, '.');

            if ($isHeading) {
                $sections[] = [
                    'type' => 'heading',
                    'content' => $paragraph,
                    'level' => 1,
                    'raw' => $paragraph,
                ];
            } else {
                $sections[] = [
                    'type' => 'text',
                    'content' => $paragraph,
                    'level' => 0,
                    'raw' => $paragraph,
                ];
            }
        }

        return $sections;
    }

    /**
     * Merge consecutive sections of the same type (e.g. multiple text paragraphs → one element).
     *
     * @param list<ParsedSection> $sections
     * @return list<ParsedSection>
     */
    private function mergeConsecutiveSections(array $sections): array
    {
        if (\count($sections) < 2) {
            return $sections;
        }

        $merged = [];
        $current = $sections[0];

        for ($i = 1, $len = \count($sections); $i < $len; $i++) {
            $next = $sections[$i];

            // Merge consecutive text or list sections
            if ($current['type'] === $next['type'] && \in_array($current['type'], ['text', 'list'], true)) {
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

    /**
     * Map a parsed section to a TYPO3 content element proposal.
     *
     * @param ParsedSection $section
     * @param list<string> $availableCTypes
     * @return ContentElement
     */
    private function mapSectionToElement(array $section, int $index, array $availableCTypes): array
    {
        $sectionType = $section['type'];
        $preferredCType = self::CTYPE_MAP[$sectionType] ?? 'text';

        // Resolve to an available CType
        $ctype = $this->resolveAvailableCType($preferredCType, $availableCTypes);

        $element = [
            'index' => $index,
            'CType' => $ctype,
            'header' => '',
            'bodytext' => '',
            'header_layout' => 0,
            'summary' => '',
        ];

        switch ($sectionType) {
            case 'heading':
                $element['header'] = $section['content'];
                $element['header_layout'] = $section['level'];
                if ($ctype === 'header') {
                    $element['summary'] = 'H' . $section['level'] . ' heading';
                } else {
                    // Fallback: heading as text element with header field
                    $element['summary'] = 'H' . $section['level'] . ' heading (as ' . $ctype . ')';
                }
                break;

            case 'text':
                $paragraphCount = substr_count($section['content'], "\n\n") + 1;
                $element['bodytext'] = $section['content'];
                $element['summary'] = $paragraphCount > 1
                    ? $paragraphCount . ' paragraphs of text'
                    : 'Text paragraph';
                break;

            case 'list':
                $element['bodytext'] = $section['content'];
                $itemCount = preg_match_all('/^\s*[-*+]\s|^\s*\d+\.\s/m', $section['content']);
                $element['summary'] = $itemCount . ' list items';
                break;

            case 'html':
            case 'table':
            case 'code':
                $element['bodytext'] = $section['raw'];
                $element['summary'] = match ($sectionType) {
                    'table' => 'HTML table',
                    'code' => 'Code block',
                    default => 'HTML content',
                };
                break;

            case 'image':
                $element['header'] = $section['content'];
                $element['summary'] = 'Image reference (file must be attached separately via WriteTable)';
                break;

            default:
                $element['bodytext'] = $section['content'];
                $element['summary'] = 'Content block';
                break;
        }

        return $element;
    }

    /**
     * Resolve a preferred CType to one that's actually available.
     *
     * @param list<string> $availableCTypes
     */
    private function resolveAvailableCType(string $preferred, array $availableCTypes): string
    {
        if (\in_array($preferred, $availableCTypes, true)) {
            return $preferred;
        }

        $fallbacks = self::CTYPE_FALLBACKS[$preferred] ?? ['text'];
        foreach ($fallbacks as $fallback) {
            if (\in_array($fallback, $availableCTypes, true)) {
                return $fallback;
            }
        }

        // Last resort: first available CType
        return $availableCTypes[0] ?? 'text';
    }
}
