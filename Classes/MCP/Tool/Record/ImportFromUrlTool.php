<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fetch content from a URL, analyze it, and optionally create a TYPO3 page with content elements.
 *
 * In "analyze" mode (default) the tool returns a read-only proposal describing the
 * page title, slug, content sections mapped to CTypes, and discovered images.
 * In "execute" mode it creates the page and content elements via DataHandler.
 *
 * @phpstan-type ContentSection array{type: string, content: string, level: int, raw: string}
 * @phpstan-type ImageRef array{src: string, alt: string}
 * @phpstan-type CTypeProfile array{label: string, hasBodytext: bool, hasHeader: bool, hasImage: bool, hasAssets: bool, fields: list<string>}
 * @phpstan-type ProposedElement array{index: int, CType: string, header: string, bodytext: string, header_layout: int, summary: string}
 */
final class ImportFromUrlTool extends AbstractRecordTool
{
    private const MAX_CONTENT_SIZE = 5 * 1024 * 1024; // 5 MB
    private const REQUEST_TIMEOUT = 30;

    private const MODE_ANALYZE = 'analyze';
    private const MODE_EXECUTE = 'execute';

    /**
     * Section types produced by the HTML parser.
     * Each maps to a set of field requirements used for CType scoring.
     */
    private const SECTION_NEEDS = [
        // type => [needs_bodytext, needs_header_only, needs_image, prefers_raw_html]
        'heading' => [false, true, false, false],
        'text'    => [true, false, false, false],
        'list'    => [true, false, false, false],
        'html'    => [true, false, false, true],
        'table'   => [true, false, false, true],
        'code'    => [true, false, false, true],
        'image'   => [false, false, true, false],
    ];

    /**
     * Private / reserved IPv4 ranges for SSRF protection (CIDR).
     */
    private const BLOCKED_CIDR4 = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '100.64.0.0/10',   // Shared address space (RFC 6598)
        '192.0.0.0/24',    // IETF protocol assignments
        '192.0.2.0/24',    // TEST-NET-1
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24',  // TEST-NET-3
        '224.0.0.0/4',     // Multicast
        '240.0.0.0/4',     // Reserved
    ];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Fetch a URL, extract its content, and propose (or create) a TYPO3 page with content elements. '
                . 'In "analyze" mode (default) this is read-only: it returns the proposed page title, slug, '
                . 'content sections mapped to CTypes, and discovered images. '
                . 'In "execute" mode it creates the page and content elements via DataHandler and returns their UIDs. '
                . 'SSRF-safe: rejects private/reserved IP ranges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL to fetch (http or https only)',
                    ],
                    'targetPid' => [
                        'type' => 'integer',
                        'description' => 'Parent page ID where the new page will be created',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'description' => 'Operation mode: "analyze" (read-only proposal, default) or "execute" (creates page + elements)',
                        'enum' => [self::MODE_ANALYZE, self::MODE_EXECUTE],
                        'default' => self::MODE_ANALYZE,
                    ],
                    'colPos' => [
                        'type' => 'integer',
                        'description' => 'Column position for content elements, default: 0 (main content area)',
                        'default' => 0,
                    ],
                    'pageType' => [
                        'type' => 'integer',
                        'description' => 'Page doktype, default: 1 (standard page)',
                        'default' => 1,
                    ],
                ],
                'required' => ['url', 'targetPid'],
            ],
            'annotations' => [
                'readOnlyHint' => true, // overridden dynamically in doExecute for execute mode
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $url = is_string($params['url'] ?? null) ? trim($params['url']) : '';
        $targetPid = is_numeric($params['targetPid'] ?? null) ? (int)$params['targetPid'] : 0;
        $mode = is_string($params['mode'] ?? null) ? $params['mode'] : self::MODE_ANALYZE;
        $colPos = is_numeric($params['colPos'] ?? null) ? (int)$params['colPos'] : 0;
        $pageType = is_numeric($params['pageType'] ?? null) ? (int)$params['pageType'] : 1;

        // --- Validation -----------------------------------------------------------
        $errors = [];
        if ($url === '') {
            $errors[] = 'url must not be empty';
        }
        if ($targetPid < 0) {
            $errors[] = 'targetPid must be a non-negative integer';
        }
        if (!in_array($mode, [self::MODE_ANALYZE, self::MODE_EXECUTE], true)) {
            $errors[] = 'mode must be "analyze" or "execute"';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // --- URL scheme check -----------------------------------------------------
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException(['Only http and https URLs are allowed']);
        }

        // --- SSRF protection ------------------------------------------------------
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new ValidationException(['URL has no host']);
        }
        $this->validateHostSafety($host);

        // --- Fetch the HTML -------------------------------------------------------
        $html = $this->fetchUrl($url);

        // --- Parse ----------------------------------------------------------------
        $title = $this->extractTitle($html);
        $slug = $this->deriveSlug($url);
        $mainHtml = $this->extractMainContent($html);
        $images = $this->extractImages($mainHtml);
        $sections = $this->parseHtmlSections($mainHtml);
        $sections = $this->mergeConsecutiveSections($sections);

        // --- Build CType profiles -------------------------------------------------
        $this->ensureTableAccess('tt_content', 'write');
        $availableTypes = $this->tableAccessService->getAvailableTypes('tt_content');
        $ctypeProfiles = $this->buildCTypeProfiles($availableTypes);

        // --- Map sections to proposed elements ------------------------------------
        $elements = [];
        foreach ($sections as $index => $section) {
            $elements[] = $this->mapSectionToElement($section, $index, $ctypeProfiles);
        }

        // --- Build the available types summary ------------------------------------
        $typeSummary = [];
        foreach ($ctypeProfiles as $ctype => $profile) {
            $typeSummary[$ctype] = [
                'label' => $profile['label'],
                'fields' => $profile['fields'],
            ];
        }

        // --- Execute mode: create page + elements ---------------------------------
        if ($mode === self::MODE_EXECUTE) {
            $this->ensureTableAccess('pages', 'write');

            $createResult = $this->createPageAndElements(
                $title,
                $slug,
                $targetPid,
                $pageType,
                $colPos,
                $elements,
            );

            return $this->createJsonResult([
                'mode' => 'execute',
                'sourceUrl' => $url,
                'page' => $createResult['page'],
                'elements' => $createResult['elements'],
                'images' => $images,
                'hint' => 'Page and content elements created. Images were discovered but NOT uploaded — '
                    . 'use the file tools to download and attach them via sys_file_reference.',
            ]);
        }

        // --- Analyze mode (default): return proposal ------------------------------
        return $this->createJsonResult([
            'mode' => 'analyze',
            'sourceUrl' => $url,
            'proposedTitle' => $title,
            'proposedSlug' => $slug,
            'targetPid' => $targetPid,
            'colPos' => $colPos,
            'pageType' => $pageType,
            'availableContentTypes' => $typeSummary,
            'elements' => $elements,
            'totalElements' => count($elements),
            'images' => $images,
            'hint' => 'Review the proposal, then call ImportFromUrl again with mode="execute" to create the page '
                . 'and content elements. Or use BulkWrite to create them manually.',
        ]);
    }

    // -----------------------------------------------------------------------
    // SSRF protection
    // -----------------------------------------------------------------------

    /**
     * Resolve hostname and reject private/reserved IPs.
     */
    private function validateHostSafety(string $host): void
    {
        // Reject literal IPv6 loopback
        $cleanHost = trim($host, '[]');
        if (in_array($cleanHost, ['::1', '::'], true)) {
            throw new ValidationException(['URL resolves to a private/reserved IP address']);
        }

        // If it already looks like an IPv4 literal, check directly
        if (filter_var($cleanHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($this->isBlockedIpv4($cleanHost)) {
                throw new ValidationException(['URL resolves to a private/reserved IP address']);
            }
            return;
        }

        // DNS resolution
        $ips = gethostbynamel($cleanHost);
        if ($ips === false || $ips === []) {
            throw new ValidationException(['Could not resolve hostname: ' . $host]);
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIpv4($ip)) {
                throw new ValidationException(['URL resolves to a private/reserved IP address']);
            }
        }
    }

    /**
     * Check whether an IPv4 address falls within any blocked CIDR range.
     */
    private function isBlockedIpv4(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return true; // unparseable = blocked
        }

        foreach (self::BLOCKED_CIDR4 as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - (int)$bits);
            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // HTTP fetch
    // -----------------------------------------------------------------------

    private function fetchUrl(string $url): string
    {
        $response = $this->requestFactory->request($url, 'GET', [
            'headers' => [
                'User-Agent' => 'TYPO3-MCP-ImportFromUrl/1.0',
                'Accept' => 'text/html, application/xhtml+xml',
            ],
            'timeout' => self::REQUEST_TIMEOUT,
            'allow_redirects' => [
                'max' => 5,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 400) {
            throw new ValidationException(['HTTP request failed with status ' . $statusCode]);
        }

        $body = $response->getBody()->getContents();
        if (strlen($body) > self::MAX_CONTENT_SIZE) {
            throw new ValidationException(['Response body exceeds maximum size of ' . (self::MAX_CONTENT_SIZE / 1024 / 1024) . ' MB']);
        }

        if (trim($body) === '') {
            throw new ValidationException(['URL returned empty content']);
        }

        return $body;
    }

    // -----------------------------------------------------------------------
    // HTML extraction helpers
    // -----------------------------------------------------------------------

    /**
     * Extract the page title from <title> or the first <h1>.
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
            if ($title !== '') {
                return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
            if ($title !== '') {
                return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return 'Imported page';
    }

    /**
     * Derive a URL-safe slug from the URL path.
     */
    private function deriveSlug(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';
        $path = trim($path, '/');

        if ($path === '' || $path === 'index.html' || $path === 'index.php') {
            $host = $parsed['host'] ?? 'imported';
            return '/' . preg_replace('/[^a-z0-9-]/', '-', strtolower($host));
        }

        // Remove file extension
        $path = preg_replace('/\.[a-z]{2,5}$/i', '', $path) ?? $path;

        // Normalize to slug characters
        $slug = strtolower($path);
        $slug = (string)preg_replace('/[^a-z0-9\/-]/', '-', $slug);
        $slug = (string)preg_replace('/-{2,}/', '-', $slug);
        $slug = trim($slug, '-/');

        return '/' . $slug;
    }

    /**
     * Strip non-content elements and return the main content HTML.
     */
    private function extractMainContent(string $html): string
    {
        // Suppress libxml errors for malformed HTML
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Remove unwanted elements
        $xpath = new \DOMXPath($dom);
        $tagsToRemove = ['script', 'style', 'nav', 'footer', 'header', 'aside', 'iframe', 'noscript', 'svg'];
        foreach ($tagsToRemove as $tag) {
            $nodes = $xpath->query('//' . $tag);
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    if ($node instanceof \DOMNode && $node->parentNode instanceof \DOMNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        // Try to find a main content container
        $contentSelectors = [
            '//article',
            '//main',
            '//*[contains(@class, "content")]',
            '//body',
        ];

        foreach ($contentSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                $contentNode = $nodes->item(0);
                if ($contentNode instanceof \DOMElement) {
                    return $dom->saveHTML($contentNode) ?: '';
                }
            }
        }

        return $dom->saveHTML() ?: '';
    }

    /**
     * Find <img> tags and collect their src URLs and alt text.
     *
     * @return list<ImageRef>
     */
    private function extractImages(string $html): array
    {
        $images = [];
        if (preg_match_all('/<img\s[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $imgTag) {
                $src = '';
                $alt = '';
                if (preg_match('/src=["\']([^"\']+)["\']/', $imgTag, $sm)) {
                    $src = $sm[1];
                }
                if (preg_match('/alt=["\']([^"\']*)["\']/', $imgTag, $am)) {
                    $alt = $am[1];
                }
                if ($src !== '') {
                    $images[] = ['src' => $src, 'alt' => $alt];
                }
            }
        }

        return $images;
    }

    // -----------------------------------------------------------------------
    // HTML section parser (mirrors ImportContentTool's approach)
    // -----------------------------------------------------------------------

    /**
     * Parse cleaned HTML into typed content sections.
     *
     * @return list<ContentSection>
     */
    private function parseHtmlSections(string $html): array
    {
        $sections = [];
        $html = trim($html);

        $pattern = '/(<(?:h[1-6]|table|pre|ul|ol|blockquote|hr)[^>]*>.*?<\/(?:h[1-6]|table|pre|ul|ol|blockquote)>|<hr\s*\/?>|<img\s[^>]*\/?>)/is';

        $parts = preg_split($pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            $parts = [$html];
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
                continue; // Skip horizontal rules
            }
            if (preg_match('/^<img\b/i', $part)) {
                $alt = '';
                if (preg_match('/alt=["\']([^"\']*)["\']/', $part, $am)) {
                    $alt = $am[1];
                }
                $sections[] = ['type' => 'image', 'content' => $alt, 'level' => 0, 'raw' => $part];
                continue;
            }

            // Plain text / paragraphs
            $textContent = preg_replace('/<\/?p[^>]*>/i', '', $part);
            $textContent = is_string($textContent) ? trim($textContent) : trim($part);
            if ($textContent !== '') {
                $sections[] = ['type' => 'text', 'content' => $textContent, 'level' => 0, 'raw' => $part];
            }
        }

        return $sections;
    }

    /**
     * Merge consecutive sections of the same type (text, list).
     *
     * @param list<ContentSection> $sections
     * @return list<ContentSection>
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

    // -----------------------------------------------------------------------
    // CType profiling and scoring (mirrors ImportContentTool)
    // -----------------------------------------------------------------------

    /**
     * Build field profiles for all available CTypes by querying TCA.
     *
     * @param array<string, string> $availableTypes
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
     * Score and select the best CType for a section.
     *
     * @param ContentSection $section
     * @param array<string, CTypeProfile> $profiles
     * @return ProposedElement
     */
    private function mapSectionToElement(array $section, int $index, array $profiles): array
    {
        $sectionType = $section['type'];
        $needs = self::SECTION_NEEDS[$sectionType] ?? [true, false, false, false];
        [$needsBodytext, $needsHeaderOnly, $needsImage, $prefersRawHtml] = $needs;

        $bestCType = '';
        $bestScore = -1;

        foreach ($profiles as $ctype => $profile) {
            $score = $this->scoreCType($ctype, $profile, $needsBodytext, $needsHeaderOnly, $needsImage, $prefersRawHtml);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCType = $ctype;
            }
        }

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
                $element['summary'] = 'H' . $section['level'] . ' heading -> ' . $label;
                break;

            case 'text':
                $paragraphCount = substr_count($section['content'], "\n\n") + 1;
                $element['bodytext'] = $section['content'];
                $element['summary'] = ($paragraphCount > 1 ? $paragraphCount . ' paragraphs' : 'Text') . ' -> ' . $label;
                break;

            case 'list':
                $element['bodytext'] = $section['content'];
                $element['summary'] = 'List -> ' . $label;
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
                $element['summary'] = $typeLabel . ' -> ' . $label;
                break;

            case 'image':
                $element['header'] = $section['content'];
                $element['summary'] = 'Image reference -> ' . $label . ' (attach file via WriteTable)';
                break;

            default:
                $element['bodytext'] = $section['content'];
                $element['summary'] = 'Content -> ' . $label;
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
            if ($profile['hasHeader'] && !$profile['hasBodytext']) {
                $score += 100;
            } elseif ($profile['hasHeader']) {
                $score += 50;
            }
            $score -= max(0, count($profile['fields']) - 5);
        } elseif ($needsImage) {
            if ($profile['hasImage'] || $profile['hasAssets']) {
                $score += 100;
            }
            if (!$profile['hasBodytext']) {
                $score += 20;
            }
        } elseif ($needsBodytext) {
            if (!$profile['hasBodytext']) {
                return -100;
            }
            $score += 50;

            if ($prefersRawHtml) {
                if ($ctype === 'html') {
                    $score += 80;
                }
                $score -= max(0, count($profile['fields']) - 3);
            } else {
                if ($profile['hasHeader']) {
                    $score += 20;
                }
                if ($ctype === 'text') {
                    $score += 30;
                }
                if ($profile['hasImage'] || $profile['hasAssets']) {
                    $score += 5;
                }
            }
        }

        return $score;
    }

    // -----------------------------------------------------------------------
    // DataHandler: create page + content elements (execute mode)
    // -----------------------------------------------------------------------

    /**
     * Create the page and all content elements via DataHandler.
     *
     * @param list<ProposedElement> $elements
     * @return array{page: array{uid: int, title: string, slug: string}, elements: list<array{uid: int, CType: string, summary: string}>}
     */
    private function createPageAndElements(
        string $title,
        string $slug,
        int $targetPid,
        int $pageType,
        int $colPos,
        array $elements,
    ): array {
        $backendUser = $this->getBackendUser();

        // --- Create the page ------------------------------------------------------
        $pageNewId = 'NEW_page_' . uniqid();
        $pageDataMap = [
            'pages' => [
                $pageNewId => [
                    'pid' => $targetPid,
                    'title' => $title,
                    'slug' => $slug,
                    'doktype' => $pageType,
                ],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start($pageDataMap, []);
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            throw new \RuntimeException(
                'Error creating page: ' . implode(', ', $dataHandler->errorLog),
            );
        }

        $pageUid = (int)($dataHandler->substNEWwithIDs[$pageNewId] ?? 0);
        if ($pageUid === 0) {
            throw new \RuntimeException('DataHandler did not return a UID for the new page');
        }

        // --- Create content elements ----------------------------------------------
        $contentDataMap = ['tt_content' => []];
        $newIdMap = []; // newId => index

        foreach ($elements as $idx => $element) {
            $newId = 'NEW_ce_' . $idx . '_' . uniqid();
            $newIdMap[$newId] = $idx;

            $record = [
                'pid' => $pageUid,
                'CType' => $element['CType'],
                'colPos' => $colPos,
                'sorting' => ($idx + 1) * 256,
            ];

            if (($element['header'] ?? '') !== '') {
                $record['header'] = $element['header'];
            }
            if (($element['bodytext'] ?? '') !== '') {
                $record['bodytext'] = $element['bodytext'];
            }
            if (($element['header_layout'] ?? 0) > 0) {
                $record['header_layout'] = $element['header_layout'];
            }

            $contentDataMap['tt_content'][$newId] = $record;
        }

        $ceDataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $ceDataHandler->BE_USER = $backendUser;
        $ceDataHandler->start($contentDataMap, []);
        $ceDataHandler->process_datamap();

        if (!empty($ceDataHandler->errorLog)) {
            throw new \RuntimeException(
                'Page created (uid=' . $pageUid . ') but error creating content elements: '
                . implode(', ', $ceDataHandler->errorLog),
            );
        }

        // Collect created element UIDs
        $createdElements = [];
        foreach ($newIdMap as $newId => $idx) {
            $ceUid = (int)($ceDataHandler->substNEWwithIDs[$newId] ?? 0);
            $createdElements[] = [
                'uid' => $ceUid,
                'CType' => $elements[$idx]['CType'],
                'summary' => $elements[$idx]['summary'],
            ];
        }

        return [
            'page' => [
                'uid' => $pageUid,
                'title' => $title,
                'slug' => $slug,
            ],
            'elements' => $createdElements,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Backend user context not initialized');
        }

        return $backendUser;
    }
}
