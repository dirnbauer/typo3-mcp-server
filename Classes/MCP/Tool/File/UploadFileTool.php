<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use TYPO3\CMS\Core\Resource\Folder;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\McpFileHarnessService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class UploadFileTool extends AbstractTool
{
    private const METADATA_FIELDS = ['title', 'description', 'alternative', 'copyright'];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly McpFileHarnessService $fileHarnessService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Upload a binary or text file into the MCP file harness. '
                . 'All uploads are restricted to the configured harness root (default: fileadmin/mcp/). '
                . 'When workspace upload subfolders are enabled, files are stored below a workspace-specific folder to reduce collisions with live content. '
                . 'Existing files are never overwritten.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP harness. '
                            . 'Use a relative path like "images/product-photo.png" or an absolute combined identifier inside the harness. '
                            . 'The folder path is respected, but the stored filename is randomized for security.',
                    ],
                    'content_base64' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file contents. Data URLs such as "data:image/png;base64,..." are also accepted.',
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
                'required' => ['path', 'content_base64'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $path = \is_string($params['path'] ?? null) ? $params['path'] : '';
        $contentBase64 = \is_string($params['content_base64'] ?? null) ? $params['content_base64'] : '';
        $metadata = \is_array($params['metadata'] ?? null) ? $this->sanitizeMetadata($params['metadata']) : [];

        if ($path === '') {
            throw new ValidationException(['Parameter "path" is required. Use a relative path or a combined identifier inside the MCP harness.']);
        }
        if ($contentBase64 === '') {
            throw new ValidationException(['Parameter "content_base64" is required.']);
        }

        $decodedContent = $this->decodeBase64Content($contentBase64);
        $target = $this->fileHarnessService->resolveUploadTarget($path);
        $storage = $this->resolveStorage($target['storageUid']);
        $folder = $this->ensureFolder($storage, $target['folderPath']);

        $storedFileName = $this->createUniqueStoredFileName($folder, $target['fileName']);

        $tempFile = GeneralUtility::tempnam('mcp_upload_');
        try {
            file_put_contents($tempFile, $decodedContent);
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
            'action' => 'uploaded',
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

    private function decodeBase64Content(string $contentBase64): string
    {
        $payload = trim($contentBase64);
        if (str_starts_with($payload, 'data:')) {
            $commaPosition = strpos($payload, ',');
            if ($commaPosition === false) {
                throw new ValidationException(['Invalid data URL. Expected a comma before the base64 payload.']);
            }
            $payload = substr($payload, $commaPosition + 1);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new ValidationException(['Parameter "content_base64" must contain valid base64 data or a valid data URL.']);
        }

        return $decoded;
    }

    private function createUniqueStoredFileName(Folder $folder, string $requestedFileName): string
    {
        $extension = strtolower(pathinfo($requestedFileName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new ValidationException(['Uploaded files must include a filename extension.']);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->fileHarnessService->buildStoredUploadFileName($requestedFileName);
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
            if (isset($raw[$field]) && \is_string($raw[$field])) {
                $clean[$field] = $raw[$field];
            }
        }

        return $clean;
    }
}
