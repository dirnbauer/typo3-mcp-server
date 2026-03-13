<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use InvalidArgumentException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BrowseFilesTool extends AbstractTool
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
            'description' => 'Browse file storages and folders in TYPO3 (fileadmin). '
                . 'List available storages, browse folder contents, and view file metadata. '
                . 'Physical files are NOT versioned in workspaces -- uploading or overwriting a file affects all workspaces immediately.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Folder path to browse, e.g. "1:/" for storage root, "1:/user_upload/images/". '
                            . 'Format: "<storageId>:/<folder/path/>". Omit to list all storages.',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'description' => 'Include subfolder listing (default: false)',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $path = \is_string($params['path'] ?? null) ? $params['path'] : null;
        $recursive = (bool) ($params['recursive'] ?? false);

        if ($path === null || $path === '') {
            return $this->listStorages();
        }

        return $this->browseFolder($path, $recursive);
    }

    private function listStorages(): CallToolResult
    {
        $storages = $this->storageRepository->findAll();
        $lines = ["FILE STORAGES\n=============\n"];

        foreach ($storages as $storage) {
            if (!$storage->isOnline() || !$storage->isBrowsable()) {
                continue;
            }

            $lines[] = \sprintf(
                "- Storage %d: %s (driver: %s, writable: %s)\n  Browse with path: \"%d:/\"",
                $storage->getUid(),
                $storage->getName(),
                $storage->getDriverType(),
                $storage->isWritable() ? 'yes' : 'no',
                $storage->getUid(),
            );
        }

        if (\count($lines) === 1) {
            $lines[] = '(No accessible storages found)';
        }

        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }

    private function browseFolder(string $path, bool $recursive): CallToolResult
    {
        try {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($path);
        } catch (FolderDoesNotExistException) {
            throw new ValidationException(["Folder not found: {$path}"]);
        } catch (InvalidArgumentException) {
            throw new ValidationException(["Invalid path format: {$path}. Use \"<storageId>:/<folder/path/>\" (e.g. \"1:/user_upload/\")"]);
        }

        $storage = $folder->getStorage();
        if (!$storage->isOnline() || !$storage->isBrowsable()) {
            throw new ValidationException(['Storage is not available or not browsable']);
        }

        $lines = [\sprintf("FOLDER: %s (Storage %d: %s)\n", $folder->getIdentifier(), $storage->getUid(), $storage->getName())];

        $subfolders = $folder->getSubfolders();
        if (!empty($subfolders)) {
            $lines[] = "SUBFOLDERS:";
            foreach ($subfolders as $subfolder) {
                $combinedId = $storage->getUid() . ':' . $subfolder->getIdentifier();
                $lines[] = \sprintf("  [DIR] %s  -> \"%s\"", $subfolder->getName(), $combinedId);

                if ($recursive) {
                    $this->listSubfolderContents($subfolder, $storage, $lines, '    ');
                }
            }
            $lines[] = '';
        }

        $files = $folder->getFiles();
        if (!empty($files)) {
            $lines[] = \sprintf("FILES (%d):", \count($files));
            foreach ($files as $file) {
                $lines[] = $this->formatFileInfo($file);
            }
        } else {
            $lines[] = "(No files in this folder)";
        }

        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }

    /**
     * @param list<string> $lines
     */
    private function listSubfolderContents(Folder $folder, ResourceStorage $storage, array &$lines, string $indent): void
    {
        foreach ($folder->getSubfolders() as $sub) {
            $combinedId = $storage->getUid() . ':' . $sub->getIdentifier();
            $lines[] = \sprintf("%s[DIR] %s  -> \"%s\"", $indent, $sub->getName(), $combinedId);
        }
        $fileCount = $folder->getFileCount();
        if ($fileCount > 0) {
            $lines[] = \sprintf("%s(%d files)", $indent, $fileCount);
        }
    }

    private function formatFileInfo(File $file): string
    {
        $size = GeneralUtility::formatSize($file->getSize(), 'si');
        $meta = '';

        if (str_starts_with($file->getMimeType(), 'image/')) {
            $props = $file->getProperties();
            $w = is_numeric($props['width'] ?? null) ? (int) $props['width'] : 0;
            $h = is_numeric($props['height'] ?? null) ? (int) $props['height'] : 0;
            if ($w > 0 && $h > 0) {
                $meta = \sprintf(' [%dx%d]', $w, $h);
            }
        }

        return \sprintf(
            "  %s  (%s, %s%s)  uid=%d",
            $file->getName(),
            $file->getMimeType(),
            $size,
            $meta,
            $file->getUid(),
        );
    }
}
