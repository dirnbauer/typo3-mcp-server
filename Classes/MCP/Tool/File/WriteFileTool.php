<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\McpFileSandboxService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP tool for creating and updating text-based files in TYPO3 file storages.
 *
 * Physical files are NOT workspace-versioned in TYPO3.
 * Writing a file affects all workspaces immediately.
 */
final class WriteFileTool extends AbstractTool
{
    private const METADATA_FIELDS = ['title', 'description', 'alternative', 'copyright'];

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly McpFileSandboxService $fileSandboxService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Create or overwrite a text-based file inside the MCP file sandbox, and/or update its metadata. '
                . 'The configured sandbox defaults to fileadmin/mcp/ and all paths are restricted to that area. '
                . 'Supports text files such as .txt, .html, .css, .js, .json, .xml, .csv, .svg, .yaml, .md. '
                . 'Binary file uploads (images, PDFs, etc.) are NOT supported. '
                . 'Can also update metadata (title, description, alt text, copyright) on any existing file — including images — without changing the file content. '
                . 'WARNING: Physical files are NOT workspace-versioned — changes take effect immediately across all workspaces.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path inside the MCP file sandbox. '
                            . 'Use either a relative path like "notes/data.json" or an absolute combined identifier inside the sandbox such as "1:/mcp/notes/data.json". '
                            . 'Parent folders are created automatically when writing content.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The text content to write to the file. Omit to only update metadata on an existing file.',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'description' => 'If true, overwrite an existing file. If false (default), fail when the file already exists.',
                    ],
                    'metadata' => [
                        'type' => 'object',
                        'description' => 'File metadata to set or update. Works on new files and existing files (including images).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'File title'],
                            'description' => ['type' => 'string', 'description' => 'File description'],
                            'alternative' => ['type' => 'string', 'description' => 'Alternative text (used as alt attribute for images)'],
                            'copyright' => ['type' => 'string', 'description' => 'Copyright notice'],
                        ],
                    ],
                ],
                'required' => ['path'],
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
        $path = \is_string($params['path'] ?? null) ? $params['path'] : '';
        $content = \is_string($params['content'] ?? null) ? $params['content'] : null;
        $overwrite = (bool)($params['overwrite'] ?? false);
        $metadata = \is_array($params['metadata'] ?? null) ? $this->sanitizeMetadata($params['metadata']) : [];

        if ($path === '') {
            throw new ValidationException(['Parameter "path" is required. Use a relative path or a combined identifier inside the MCP file sandbox.']);
        }

        if ($content === null && empty($metadata)) {
            throw new ValidationException(['Either "content" or "metadata" (or both) must be provided.']);
        }

        $parsed = $this->fileSandboxService->resolveFileTarget($path);
        $storage = $this->resolveStorage($parsed['storageUid']);

        if ($content === null) {
            return $this->updateMetadataOnly($storage, $parsed, $metadata);
        }

        $this->validateExtension($parsed['fileName']);
        $folder = $this->ensureFolder($storage, $parsed['folderPath']);

        if ($folder->hasFile($parsed['fileName'])) {
            if (!$overwrite) {
                throw new ValidationException([
                    "File already exists: {$path}. Set overwrite=true to replace it.",
                ]);
            }
            $existingFile = $this->getExistingFile($storage, $folder, $parsed['fileName']);
            $existingFile->setContents($content);

            if (!empty($metadata)) {
                $this->applyMetadata($existingFile, $metadata);
            }

            return $this->buildResult('overwritten', $existingFile, $metadata);
        }

        $tempFile = GeneralUtility::tempnam('mcp_write_');
        try {
            file_put_contents($tempFile, $content);
            $newFile = $storage->addFile($tempFile, $folder, $parsed['fileName']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        if (!empty($metadata)) {
            $this->applyMetadata($newFile, $metadata);
        }

        return $this->buildResult('created', $newFile, $metadata);
    }

    /**
     * @param array{storageUid: int, folderPath: string, fileName: string} $parsed
     * @param array<string, string> $metadata
     */
    private function updateMetadataOnly(ResourceStorage $storage, array $parsed, array $metadata): CallToolResult
    {
        $folderPath = $parsed['folderPath'];
        if (!$storage->hasFolder($folderPath)) {
            throw new ValidationException(["Folder not found: {$parsed['storageUid']}:{$folderPath}"]);
        }
        $folder = $storage->getFolder($folderPath);

        if (!$folder->hasFile($parsed['fileName'])) {
            throw new ValidationException([
                "File not found: {$parsed['storageUid']}:{$folderPath}{$parsed['fileName']}. "
                . 'To update metadata, the file must already exist.',
            ]);
        }

        $file = $this->getExistingFile($storage, $folder, $parsed['fileName']);
        $this->applyMetadata($file, $metadata);

        return $this->buildResult('metadata_updated', $file, $metadata);
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
     * @param array<string, string> $metadata
     */
    private function buildResult(string $action, File $file, array $metadata): CallToolResult
    {
        $result = [
            'action' => $action,
            'identifier' => $file->getCombinedIdentifier(),
            'uid' => $file->getUid(),
            'size' => $file->getSize(),
        ];

        if (!empty($metadata)) {
            $result['metadata'] = $metadata;
        }

        return new CallToolResult([new TextContent(
            json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        )]);
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

    private function validateExtension(string $fileName): void
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            throw new ValidationException(['Filename must have an extension (e.g. .txt, .html, .json).']);
        }

        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConfig = \is_array($confVars) && \is_array($confVars['SYS'] ?? null)
            ? $confVars['SYS']
            : [];
        $configuredTextExtensions = \is_string($sysConfig['textfile_ext'] ?? null)
            ? $sysConfig['textfile_ext']
            : 'txt,ts,typoscript,html,htm,css,tmpl,js,sql,xml,csv,xlf,yaml,yml,md,rst,json,svg';
        $textExtensions = GeneralUtility::trimExplode(
            ',',
            $configuredTextExtensions,
            true,
        );

        if (!in_array($ext, $textExtensions, true)) {
            throw new ValidationException([
                "Extension \".{$ext}\" is not allowed for text file creation. "
                . 'Allowed: ' . implode(', ', $textExtensions) . '.',
            ]);
        }
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

    private function getExistingFile(ResourceStorage $storage, Folder $folder, string $fileName): File
    {
        $file = $storage->getFileInFolder($fileName, $folder);
        if (!$file instanceof File) {
            throw new ValidationException([
                "The target file \"{$fileName}\" could not be resolved as a writable TYPO3 file.",
            ]);
        }

        return $file;
    }
}
