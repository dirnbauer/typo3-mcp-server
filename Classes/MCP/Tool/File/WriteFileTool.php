<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
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
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Create or overwrite a text-based file in TYPO3 file storage (fileadmin). '
                . 'Supports text files such as .txt, .html, .css, .js, .json, .xml, .csv, .svg, .yaml, .md. '
                . 'Binary file uploads (images, PDFs, etc.) are NOT supported. '
                . 'WARNING: Physical files are NOT workspace-versioned — changes take effect immediately across all workspaces.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Target file path including storage ID, e.g. "1:/user_upload/data.json" or "1:/templates/header.html". '
                            . 'Format: "<storageId>:/<folder/path/filename.ext>". Parent folders are created automatically.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'The text content to write to the file.',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'description' => 'If true, overwrite an existing file. If false (default), fail when the file already exists.',
                    ],
                ],
                'required' => ['path', 'content'],
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
        $content = \is_string($params['content'] ?? null) ? $params['content'] : '';
        $overwrite = (bool) ($params['overwrite'] ?? false);

        if ($path === '') {
            throw new ValidationException(['Parameter "path" is required. Format: "<storageId>:/<folder/path/filename.ext>"']);
        }

        $parsed = $this->parseCombinedIdentifier($path);
        $storage = $this->resolveStorage($parsed['storageUid']);
        $this->validateExtension($parsed['fileName'], $storage);

        $folder = $this->ensureFolder($storage, $parsed['folderPath']);

        if ($folder->hasFile($parsed['fileName'])) {
            if (!$overwrite) {
                throw new ValidationException([
                    "File already exists: {$path}. Set overwrite=true to replace it.",
                ]);
            }
            $existingFile = $storage->getFileInFolder($parsed['fileName'], $folder);
            $existingFile->setContents($content);

            return new CallToolResult([new TextContent(json_encode([
                'action' => 'overwritten',
                'identifier' => $existingFile->getCombinedIdentifier(),
                'uid' => $existingFile->getUid(),
                'size' => $existingFile->getSize(),
            ], JSON_UNESCAPED_SLASHES) ?: '{}')]);
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

        return new CallToolResult([new TextContent(json_encode([
            'action' => 'created',
            'identifier' => $newFile->getCombinedIdentifier(),
            'uid' => $newFile->getUid(),
            'size' => $newFile->getSize(),
        ], JSON_UNESCAPED_SLASHES) ?: '{}')]);
    }

    /**
     * @return array{storageUid: int, folderPath: string, fileName: string}
     */
    private function parseCombinedIdentifier(string $path): array
    {
        if (!preg_match('#^(\d+):(/.+)$#', $path, $m)) {
            throw new ValidationException([
                'Invalid path format. Use "<storageId>:/<folder/path/filename.ext>" (e.g. "1:/user_upload/data.json").',
            ]);
        }

        $storageUid = (int) $m[1];
        $fullPath = $m[2];
        $lastSlash = strrpos($fullPath, '/');
        $folderPath = substr($fullPath, 0, $lastSlash + 1);
        $fileName = substr($fullPath, $lastSlash + 1);

        if ($fileName === '' || $fileName === false) {
            throw new ValidationException(['Path must include a filename (e.g. "1:/user_upload/data.json").']);
        }

        return [
            'storageUid' => $storageUid,
            'folderPath' => $folderPath,
            'fileName' => $fileName,
        ];
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

    private function validateExtension(string $fileName, ResourceStorage $storage): void
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext === '') {
            throw new ValidationException(['Filename must have an extension (e.g. .txt, .html, .json).']);
        }

        $textExtensions = GeneralUtility::trimExplode(
            ',',
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['textfile_ext'] ?? 'txt,ts,typoscript,html,htm,css,tmpl,js,sql,xml,csv,xlf,yaml,yml,md,rst,json,svg',
            true,
        );

        if (!in_array($ext, $textExtensions, true)) {
            throw new ValidationException([
                "Extension \".{$ext}\" is not allowed for text file creation. "
                . 'Allowed: ' . implode(', ', $textExtensions) . '.',
            ]);
        }
    }

    private function ensureFolder(ResourceStorage $storage, string $folderPath): \TYPO3\CMS\Core\Resource\Folder
    {
        if ($storage->hasFolder($folderPath)) {
            return $storage->getFolder($folderPath);
        }

        try {
            return $storage->createFolder($folderPath);
        } catch (InsufficientFolderAccessPermissionsException $e) {
            throw new ValidationException(["Permission denied: Cannot create folder \"{$folderPath}\"."]);
        } catch (ExistingTargetFolderException) {
            return $storage->getFolder($folderPath);
        }
    }
}
