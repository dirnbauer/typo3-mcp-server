<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Fetch the rendered frontend HTML for a page in workspace context.
 *
 * Closes the verification loop for an LLM editor: write a record with
 * WriteTable, then ask RenderRecord whether the result actually shows up the
 * way it should. The HTML is fetched via cURL from the workspace preview URL
 * (so unpublished workspace edits are visible) and trimmed to the requested
 * scope (full body, a single content element by `#c<uid>`, or the headline
 * text only).
 *
 * Page render only — content elements are addressed by anchor on their parent
 * page. This stays generic across CTypes / FlexForms / extensions: anything
 * the FE can render, this tool can read back.
 */
final class RenderRecordTool extends AbstractRecordTool
{
    private const MAX_LENGTH = 200000;

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly SiteInformationService $siteInformationService,
        private readonly LanguageService $languageService,
        private readonly CapabilityManifestService $capabilityManifest,
        private readonly LocalModeService $localMode,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $properties = [
            'pageId' => [
                'type' => 'integer',
                'description' => 'Live page UID to render.',
            ],
            'contentUid' => [
                'type' => 'integer',
                'description' => 'Optional tt_content UID. When set, the response is reduced to the rendered HTML of that single content element.',
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['html', 'text', 'preview'],
                'description' => '"html" returns the rendered markup; "text" strips tags and returns plain text; "preview" returns just the URL (no fetch).',
                'default' => 'html',
            ],
            'maxLength' => [
                'type' => 'integer',
                'description' => 'Cap on response size in characters (default 50000, max ' . self::MAX_LENGTH . ').',
                'default' => 50000,
            ],
        ];

        if (count($this->languageService->getAvailableIsoCodes()) > 1) {
            $properties['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code for the render (e.g. "de"). Defaults to site default.',
                'enum' => $this->languageService->getAvailableIsoCodes(),
            ];
        }

        return [
            'description' => 'Render a page (or a single content element on it) through the TYPO3 frontend in the active workspace, '
                . 'so an LLM can verify what an edit actually looks like to a visitor. Returns HTML or stripped text. '
                . 'Useful right after WriteTable to spot rendering surprises (broken FlexForm settings, missing translations, plugin errors).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => ['pageId'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
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
        $pageId = is_numeric($params['pageId'] ?? null) ? (int)$params['pageId'] : 0;
        $contentUid = is_numeric($params['contentUid'] ?? null) ? (int)$params['contentUid'] : 0;
        $mode = is_string($params['mode'] ?? null) ? strtolower($params['mode']) : 'html';
        $maxLength = is_numeric($params['maxLength'] ?? null) ? (int)$params['maxLength'] : 50000;
        $language = is_string($params['language'] ?? null) ? $params['language'] : null;

        if ($pageId <= 0) {
            throw new ValidationException(['Parameter "pageId" is required.']);
        }
        if (!in_array($mode, ['html', 'text', 'preview'], true)) {
            throw new ValidationException(['Parameter "mode" must be one of: html, text, preview.']);
        }
        $maxLength = max(1000, min(self::MAX_LENGTH, $maxLength));

        $this->ensureTableAccess('pages', 'read');

        $languageId = 0;
        if ($language !== null && $language !== '') {
            $resolved = $this->languageService->getUidFromIsoCode($language);
            if ($resolved === null) {
                throw new ValidationException(['Unknown language code: ' . $language]);
            }
            $languageId = $resolved;
        }

        $additional = [];
        if ($languageId > 0) {
            $additional['_language'] = $languageId;
        }
        try {
            $previewUri = PreviewUriBuilder::create($pageId)
                ->withAdditionalQueryParameters($additional)
                ->buildUri();
        } catch (\Throwable) {
            $previewUri = null;
        }
        $url = $previewUri !== null
            ? (string)$previewUri
            : ($this->siteInformationService->generatePageUrl($pageId, $languageId) ?? '');
        if ($url === '') {
            throw new ValidationException(['Could not build a render URL — no site is configured for page ' . $pageId . '.']);
        }
        $url = $this->siteInformationService->makeAbsoluteUrl($url) ?? $url;

        if ($mode === 'preview') {
            return $this->createJsonResult([
                'pageId' => $pageId,
                'url' => $url,
                'workspaceId' => $this->getWorkspaceId(),
                'language' => $this->languageService->getIsoCodeFromUid($languageId) ?? null,
            ]);
        }

        $html = $this->fetchUrl($url);
        if ($contentUid > 0) {
            $html = $this->extractContentElement($html, $contentUid) ?? $html;
        }

        if ($mode === 'text') {
            $html = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        }
        if (strlen($html) > $maxLength) {
            $html = substr($html, 0, $maxLength) . "\n…[truncated]";
        }

        return $this->createJsonResult([
            'pageId' => $pageId,
            'contentUid' => $contentUid > 0 ? $contentUid : null,
            'url' => $url,
            'mode' => $mode,
            'workspaceId' => $this->getWorkspaceId(),
            'language' => $this->languageService->getIsoCodeFromUid($languageId) ?? null,
            'rendered' => $html,
        ]);
    }

    private function fetchUrl(string $url): string
    {
        $parsed = parse_url($url);
        $host = is_array($parsed) ? ($parsed['host'] ?? '') : '';
        if (!is_string($host) || $host === '') {
            throw new ValidationException(['Invalid render URL — could not extract host.']);
        }
        // Manifest gate: host must be in network.outbound (default `self`).
        $this->capabilityManifest->assertHostAllowed($host);

        // SSRF defense in depth: even if the manifest allows the host (e.g.
        // `self` matches a misconfigured site whose host resolves to 127.0.0.1
        // or another internal address), refuse to dial private/reserved IPs.
        // In local mode (DDEV) DNS commonly resolves to private addresses for
        // the dev TLD, so the check is lifted there.
        if (!$this->localMode->isLocalMode()) {
            $ips = gethostbynamel($host);
            if ($ips === false || $ips === []) {
                throw new ValidationException(['Could not resolve render-host: ' . $host]);
            }
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    throw new ValidationException([
                        'Render request would target a private/reserved network address — refused.',
                    ]);
                }
            }
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ValidationException(['cURL is not available — cannot fetch render output.']);
        }
        $localMode = $this->localMode->isLocalMode();
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            // Do not follow redirects — a single HTTP/302 to a private IP would
            // bypass the host check above. If the editor's site uses redirects
            // for canonical URLs they show up as a non-2xx error and the LLM
            // can address them with WriteTable.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'TYPO3-MCP-RenderRecord/1.0',
            // Self-signed certs are common in DDEV; everywhere else we
            // verify TLS to keep MITM out of LLM-ingested HTML.
            CURLOPT_SSL_VERIFYPEER => !$localMode,
            CURLOPT_SSL_VERIFYHOST => $localMode ? 0 : 2,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $body === '') {
            throw new ValidationException([
                'Render request returned no body. HTTP ' . $status . ($error !== '' ? ' ' . $error : ''),
            ]);
        }
        if ($status >= 300) {
            throw new ValidationException(['Render request returned HTTP ' . $status . ' (redirects are not followed).']);
        }

        return $body;
    }

    private function extractContentElement(string $html, int $contentUid): ?string
    {
        // TYPO3 wraps tt_content in id="c<uid>" anchors by convention. We use a
        // tolerant regex rather than a full DOM parse so the tool stays cheap.
        $pattern = '/<[a-z][a-z0-9]*\b[^>]*\bid\s*=\s*"c' . $contentUid . '"[^>]*>(.*?)<\/[a-z][a-z0-9]*>/is';
        if (preg_match($pattern, $html, $matches) === 1) {
            return $matches[0];
        }
        return null;
    }

    private function getWorkspaceId(): int
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return $beUser instanceof BackendUserAuthentication ? (int)$beUser->workspace : 0;
    }
}
