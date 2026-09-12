<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\FileMetadataIndexService;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\McpFileSandboxService;
use Hn\McpServer\Service\OutboundUrlGuardService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fetches a file from a remote URL and stores it in the MCP file sandbox.
 *
 * This bypasses the base64 size limit of UploadFile by downloading the file
 * server-side. YouTube and Vimeo page URLs are not downloaded at all: they
 * become TYPO3 online media assets (a small placeholder file referencing the
 * video) via the core OnlineMediaHelperRegistry.
 */
final class UploadFileFromUrlTool extends AbstractTool
{
    private const METADATA_FIELDS = ['title', 'description', 'alternative', 'copyright'];
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const REQUEST_TIMEOUT = 30;
    private const ONLINE_MEDIA_HOST_PATTERN = '#^https?://(?:[a-z0-9-]+\.)*(?:youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com)(?:[/?\#]|$)#i';

    public function __construct(
        private readonly McpFileSandboxService $fileSandboxService,
        private readonly RequestFactory $requestFactory,
        private readonly CapabilityManifestService $capabilityManifest,
        private readonly LocalModeService $localMode,
        private readonly FileMetadataIndexService $fileMetadataIndexService,
        private readonly OutboundUrlGuardService $outboundUrlGuard,
        private readonly FileUploadService $fileUploadService,
        private readonly OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Download a file from a public URL and store it in the MCP file sandbox. '
                . 'Use this instead of UploadFile when the file is available via HTTP/HTTPS, '
                . 'which avoids base64 encoding size limits. '
                . 'YouTube and Vimeo video URLs are recognized and stored as TYPO3 online media assets '
                . '(the video itself is not downloaded). '
                . 'Pass a direct link to the file itself - a web page (HTML) is rejected. '
                . 'Identical content that already exists is returned instead of creating a duplicate; '
                . 'executable and server-configuration files are refused. '
                . 'In local mode (DDEV / localUnsafeMode=on), combined path identifiers may target any accessible FAL folder. '
                . sprintf('Maximum file size: %d MiB (extension setting maxFileSizeMb). ', $this->fileUploadService->getMaxFileSizeMb())
                . 'Only http:// and https:// URLs are allowed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The public HTTP or HTTPS URL to download the file from, or a YouTube/Vimeo video URL.',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP file sandbox. '
                            . 'Use a relative path like "images/photo.jpg" or "documents/report.pdf". '
                            . 'In local mode, combined identifiers may point outside the sandbox. '
                            . 'If omitted, the filename is derived from the Content-Disposition header or the URL. '
                            . 'The stored filename is randomized for security. '
                            . 'For online media only the folder part is used; the placeholder file is named after the video.',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Optional metadata to set on the uploaded file (ignored for deduplicated files).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'File title'],
                            'description' => ['type' => 'string', 'description' => 'File description'],
                            'alternative' => ['type' => 'string', 'description' => 'Alternative text (used as alt attribute for images)'],
                            'copyright' => ['type' => 'string', 'description' => 'Copyright notice'],
                        ],
                    ],
                ],
                'required' => ['url'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
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
        $path = is_string($params['path'] ?? null) ? trim($params['path']) : '';
        $metadata = is_array($params['metadata'] ?? null) ? $this->sanitizeMetadata($params['metadata']) : [];

        if ($url === '') {
            throw new ValidationException(['Parameter "url" is required.']);
        }

        $this->assertUrlFormat($url);

        // Online media (YouTube/Vimeo) never hits the download path: the URL is
        // recognized by its pattern and stored as a placeholder file via the
        // core API, so the SSRF guard (which pins the download connection) is
        // not needed for it - the manifest gate above still applies.
        if (preg_match(self::ONLINE_MEDIA_HOST_PATTERN, $url) === 1) {
            $onlineMediaResult = $this->tryCreateOnlineMedia($url, $path, $metadata);
            if ($onlineMediaResult !== null) {
                return $onlineMediaResult;
            }
        }

        $curlResolveEntry = $this->createOutboundResolveEntry($url);

        $tempFile = GeneralUtility::tempnam('mcp_url_download_');
        try {
            $downloadInfo = $this->downloadToTempFile($url, $tempFile, $curlResolveEntry);

            // A web page is not a file. Without this, the download would be
            // rejected further down with a message about file extensions or
            // mismatching content, which sends the caller hunting for the
            // wrong problem.
            if ($this->fileUploadService->looksLikeHtmlDocument($tempFile)) {
                throw new ValidationException([
                    'The URL returned a web page (HTML), not a file. Pass a direct link to the file itself '
                    . '(e.g. the image address from the page, usually ending in .jpg/.png/.pdf). '
                    . 'YouTube and Vimeo page URLs are the exception - those are recognized and embedded as videos.',
                ]);
            }

            if ($path === '' || str_ends_with($path, '/')) {
                $path .= $this->deriveFileName($url, $downloadInfo['contentType'], $downloadInfo['contentDisposition']);
            }

            $target = $this->fileSandboxService->resolveUploadTarget($path);
            $this->fileUploadService->assertFileNameIsAllowed($target['fileName']);
            $storage = $this->fileUploadService->resolveStorage($target['storageUid']);
            $folder = $this->fileUploadService->ensureFolder($storage, $target['folderPath']);
            $storedFileName = $this->fileUploadService->reserveStoredFileName($folder, $target['fileName']);

            $stored = $this->fileUploadService->storeFile($tempFile, $storedFileName, $folder);
        } finally {
            if (file_exists($tempFile)) {
                // $tempFile always comes from TYPO3's tempnam(), never from request input.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($tempFile);
            }
        }

        $newFile = $stored['file'];
        if (!$stored['deduplicated']) {
            $this->fileMetadataIndexService->ensureImageMetadataForFile($newFile);
            if ($metadata !== []) {
                $this->applyMetadata($newFile, $metadata);
            }
        }

        $result = ['action' => 'uploaded_from_url', 'sourceUrl' => $url]
            + $this->fileUploadService->describeFile($newFile, $target['fileName'], $stored['deduplicated'])
            + [
                'baseFolder' => $target['baseFolder'],
                'uploadFolder' => $target['uploadFolder'],
                'workspaceId' => $target['workspaceId'],
            ];

        if ($metadata !== [] && !$stored['deduplicated']) {
            $result['metadata'] = $metadata;
        }

        return $this->createJsonResult($result);
    }

    /**
     * Scheme and capability-manifest gate. Refuses hosts not in
     * network.outbound; the default manifest permits only configured TYPO3
     * site hosts through the `self` sentinel.
     */
    private function assertUrlFormat(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new ValidationException(['Invalid URL format.']);
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new ValidationException([
                sprintf('Only %s URLs are allowed. Got "%s".', implode(' and ', self::ALLOWED_SCHEMES), $scheme),
            ]);
        }

        $this->capabilityManifest->assertUrlAllowed($url);
    }

    private function createOutboundResolveEntry(string $url): ?string
    {
        // Local-mode (DDEV / localUnsafeMode=on) escape hatch: skip the
        // private-IP filter so workflows like "fetch from my local NAS" or
        // "fetch from a *.ddev.site URL that resolves to 127.0.0.1" work
        // without operators editing the manifest. Production mode keeps
        // the strict gate.
        if ($this->localMode->allowsUnrestrictedOutbound()) {
            return null;
        }

        return $this->outboundUrlGuard->assertPublicAndCreateCurlResolveEntry($url);
    }

    /**
     * Store a YouTube/Vimeo URL as an online media asset via the core helper.
     * Returns null when no helper recognizes the URL.
     *
     * @param array<string, string> $metadata
     */
    private function tryCreateOnlineMedia(string $url, string $path, array $metadata): ?CallToolResult
    {
        // Only the folder part of the path matters: the helper names the
        // placeholder file after the video itself.
        $folderPath = $path === '' || str_ends_with($path, '/') ? $path : dirname($path) . '/';
        $folderPath = in_array($folderPath, ['./', '.\\/'], true) ? '' : $folderPath;
        $target = $this->fileSandboxService->resolveUploadTarget($folderPath . 'online-media.tmp');
        $storage = $this->fileUploadService->resolveStorage($target['storageUid']);
        $folder = $this->fileUploadService->ensureFolder($storage, $target['folderPath']);

        $deduplicated = false;
        try {
            $file = $this->onlineMediaHelperRegistry->transformUrlToFile($url, $folder);
        } catch (OnlineMediaAlreadyExistsException $e) {
            $file = $e->getOnlineMedia();
            $deduplicated = true;
        }
        if (!$file instanceof File) {
            return null;
        }

        if (!$deduplicated && $metadata !== []) {
            $this->applyMetadata($file, $metadata);
        }

        $result = ['action' => 'online_media_created', 'onlineMedia' => true, 'sourceUrl' => $url]
            + $this->fileUploadService->describeFile($file, $file->getName(), $deduplicated)
            + [
                'baseFolder' => $target['baseFolder'],
                'uploadFolder' => $target['uploadFolder'],
                'workspaceId' => $target['workspaceId'],
            ];
        if ($metadata !== [] && !$deduplicated) {
            $result['metadata'] = $metadata;
        }

        return $this->createJsonResult($result);
    }

    /**
     * @return array{contentType: string, contentDisposition: string, size: int}
     */
    private function downloadToTempFile(string $url, string $tempFile, ?string $curlResolveEntry): array
    {
        $options = [
            'timeout' => self::REQUEST_TIMEOUT,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'TYPO3-MCP-Server/1.0',
            ],
            // Redirects are deliberately not followed. A redirect target is
            // a separate authority and must be submitted as a new URL so it
            // receives the full manifest and SSRF validation path.
            'allow_redirects' => false,
        ];
        if ($curlResolveEntry !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$curlResolveEntry]];
        }
        $response = $this->requestFactory->request($url, 'GET', $options);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ValidationException([
                sprintf('URL returned HTTP %d. Expected a 2xx response.', $statusCode),
            ]);
        }

        $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        $contentType = $contentType !== '' ? $contentType : 'application/octet-stream';
        $maxBytes = $this->fileUploadService->getMaxFileBytes();

        $body = $response->getBody();
        $fileHandle = fopen($tempFile, 'wb');
        if ($fileHandle === false) {
            throw new ValidationException(['Failed to create temporary file for download.']);
        }

        $totalBytes = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                $totalBytes += strlen($chunk);

                if ($totalBytes > $maxBytes) {
                    throw new ValidationException([$this->fileUploadService->buildSizeLimitMessage() . ' Download aborted.']);
                }

                fwrite($fileHandle, $chunk);
            }
        } finally {
            fclose($fileHandle);
        }

        if ($totalBytes === 0) {
            throw new ValidationException(['Downloaded file is empty.']);
        }

        return [
            'contentType' => $contentType,
            'contentDisposition' => $response->getHeaderLine('Content-Disposition'),
            'size' => $totalBytes,
        ];
    }

    /**
     * Derive the file name: Content-Disposition beats the URL path, the
     * Content-Type based extension is the last resort.
     */
    private function deriveFileName(string $url, string $contentType, string $contentDisposition): string
    {
        $fileName = $this->fileUploadService->extractFileNameFromContentDisposition($contentDisposition);
        if ($fileName === null) {
            $urlPath = parse_url($url, PHP_URL_PATH);
            $fileName = basename(rawurldecode(is_string($urlPath) ? $urlPath : ''));
        }

        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?? 'download';
        $fileName = trim($fileName, '-_.');

        if ($fileName === '' || !str_contains($fileName, '.')) {
            $extension = $this->guessExtensionFromMimeType($contentType);
            $fileName = ($fileName !== '' ? $fileName : 'download') . '.' . $extension;
        }

        return $fileName;
    }

    private function guessExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
        ];

        return $map[strtolower($mimeType)] ?? 'bin';
    }

    /**
     * @param array<string, string> $metadata
     */
    private function applyMetadata(File $file, array $metadata): void
    {
        $metaDataObj = $file->getMetaData();
        foreach ($metadata as $key => $value) {
            $metaDataObj->offsetSet($key, $value);
        }
        $metaDataObj->save();
    }

    /**
     * @param array<mixed, mixed> $raw
     * @return array<string, string>
     */
    private function sanitizeMetadata(array $raw): array
    {
        $clean = [];
        foreach (self::METADATA_FIELDS as $field) {
            if (isset($raw[$field]) && is_string($raw[$field])) {
                $clean[$field] = $raw[$field];
            }
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonResult(array $data): CallToolResult
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }
}
