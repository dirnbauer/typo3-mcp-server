<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\McpFileSandboxService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fetches a file from a remote URL and stores it in the MCP file sandbox.
 *
 * This bypasses the base64 size limit of UploadFile by downloading
 * the file server-side. Useful for images, documents, and other
 * binary files that are publicly accessible via HTTP(S).
 */
final class UploadFileFromUrlTool extends AbstractTool
{
    private const METADATA_FIELDS = ['title', 'description', 'alternative', 'copyright'];
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB
    private const ALLOWED_SCHEMES = ['http', 'https'];
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly McpFileSandboxService $fileSandboxService,
        private readonly RequestFactory $requestFactory,
        private readonly CapabilityManifestService $capabilityManifest,
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
                . 'Maximum file size: 20 MB. Only http:// and https:// URLs are allowed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The public HTTP or HTTPS URL to download the file from.',
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP file sandbox. '
                            . 'Use a relative path like "images/photo.jpg" or "documents/report.pdf". '
                            . 'If omitted, the filename is derived from the URL. '
                            . 'The stored filename is randomized for security.',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'Optional metadata to set on the uploaded file.',
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
                'destructiveHint' => true,
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

        $this->validateUrl($url);

        $tempFile = GeneralUtility::tempnam('mcp_url_download_');
        try {
            $downloadInfo = $this->downloadToTempFile($url, $tempFile);

            if ($path === '') {
                $path = $this->derivePathFromUrl($url, $downloadInfo['contentType']);
            }

            $target = $this->fileSandboxService->resolveUploadTarget($path);
            $storage = $this->resolveStorage($target['storageUid']);
            $folder = $this->ensureFolder($storage, $target['folderPath']);
            $storedFileName = $this->createUniqueStoredFileName($folder, $target['fileName']);

            $newFile = $storage->addFile($tempFile, $folder, $storedFileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        if (!empty($metadata)) {
            $this->applyMetadata($newFile, $metadata);
        }

        $result = [
            'action' => 'uploaded_from_url',
            'sourceUrl' => $url,
            'identifier' => $newFile->getCombinedIdentifier(),
            'uid' => $newFile->getUid(),
            'size' => $newFile->getSize(),
            'mimeType' => $newFile->getMimeType(),
            'originalFilename' => $target['fileName'],
            'storedFilename' => $storedFileName,
            'baseFolder' => $target['baseFolder'],
            'uploadFolder' => $target['uploadFolder'],
            'workspaceId' => $target['workspaceId'],
        ];

        if (!empty($metadata)) {
            $result['metadata'] = $metadata;
        }

        return new CallToolResult([
            new TextContent(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        ]);
    }

    private function validateUrl(string $url): void
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

        // Capability-manifest enforcement: refuse hosts not in
        // network.outbound. The default manifest ships with `*` so this is a
        // no-op out of the box, but a hardened deployment can replace `*`
        // with an explicit allowlist.
        $this->capabilityManifest->assertHostAllowed($parsed['host']);

        // Resolve hostname to IP and reject private/internal addresses (SSRF protection).
        // String-based hostname checks are trivially bypassed via decimal IPs, hex encoding,
        // DNS rebinding, or IPv6 embeddings — only resolved IP validation is reliable.
        $host = $parsed['host'];
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new ValidationException(['Could not resolve hostname.']);
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ValidationException(['Downloads from private or reserved network addresses are not allowed.']);
            }
        }
    }

    /**
     * @return array{contentType: string, size: int}
     */
    private function downloadToTempFile(string $url, string $tempFile): array
    {
        $response = $this->requestFactory->request($url, 'GET', [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'User-Agent' => 'TYPO3-MCP-Server/1.0',
            ],
            'allow_redirects' => [
                'max' => 5,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ValidationException([
                sprintf('URL returned HTTP %d. Expected a 2xx response.', $statusCode),
            ]);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $contentType = explode(';', $contentType)[0] ?? 'application/octet-stream';
        $contentType = trim($contentType);

        $body = $response->getBody();
        $fileHandle = fopen($tempFile, 'wb');
        if ($fileHandle === false) {
            throw new ValidationException(['Failed to create temporary file for download.']);
        }

        $totalBytes = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                $totalBytes += strlen($chunk);

                if ($totalBytes > self::MAX_FILE_SIZE) {
                    throw new ValidationException([
                        sprintf(
                            'File exceeds maximum size of %d MB. Download aborted.',
                            (int)(self::MAX_FILE_SIZE / 1024 / 1024),
                        ),
                    ]);
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
            'size' => $totalBytes,
        ];
    }

    private function derivePathFromUrl(string $url, string $contentType): string
    {
        $parsed = parse_url($url);
        $urlPath = $parsed['path'] ?? '';
        $fileName = basename($urlPath);

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
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
        ];

        return $map[strtolower($mimeType)] ?? 'bin';
    }

    private function resolveStorage(int $storageUid): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null || !$storage->isOnline()) {
            throw new ValidationException(["Storage {$storageUid} not found or offline."]);
        }
        if (!$storage->isWritable()) {
            throw new ValidationException(["Storage {$storageUid} ({$storage->getName()}) is read-only."]);
        }

        return $storage;
    }

    private function ensureFolder(ResourceStorage $storage, string $folderPath): Folder
    {
        if ($storage->hasFolder($folderPath)) {
            return $storage->getFolder($folderPath);
        }

        try {
            return $storage->createFolder($folderPath);
        } catch (InsufficientFolderAccessPermissionsException) {
            throw new ValidationException(["Permission denied: Cannot create folder \"{$folderPath}\"."]);
        } catch (ExistingTargetFolderException) {
            return $storage->getFolder($folderPath);
        }
    }

    private function createUniqueStoredFileName(Folder $folder, string $requestedFileName): string
    {
        $extension = strtolower(pathinfo($requestedFileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException(['Uploaded files must include a filename extension.']);
        }

        $fileNameValidator = GeneralUtility::makeInstance(FileNameValidator::class);
        if (!$fileNameValidator->isValid($requestedFileName)) {
            throw new ValidationException([
                sprintf('File extension "%s" is not allowed.', $extension),
            ]);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->fileSandboxService->buildStoredUploadFileName($requestedFileName);
            if (!$folder->hasFile($candidate)) {
                return $candidate;
            }
        }

        throw new ValidationException(['Could not reserve a unique upload filename. Please try again.']);
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
}
