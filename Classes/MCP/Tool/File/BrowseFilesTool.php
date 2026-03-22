<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\McpFileSandboxService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BrowseFilesTool extends AbstractTool
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly McpFileSandboxService $fileSandboxService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Browse the MCP file sandbox in TYPO3. '
                . 'All file browsing is restricted to the configured sandbox root (default: fileadmin/mcp/). '
                . 'Physical files are NOT versioned in workspaces -- uploads are sandboxed, but the underlying files still exist immediately.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Folder path inside the MCP file sandbox. '
                            . 'Use a relative path like "images/" or an absolute combined identifier inside the sandbox such as "1:/mcp/images/". '
                            . 'Omit to inspect the configured sandbox root.',
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
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $path = \is_string($params['path'] ?? null) ? $params['path'] : null;
        $recursive = (bool)($params['recursive'] ?? false);

        if ($path === null || $path === '') {
            return $this->describeSandbox($recursive);
        }

        return $this->browseFolder($path, $recursive, false);
    }

    private function describeSandbox(bool $recursive): CallToolResult
    {
        $sandbox = $this->fileSandboxService->describeSandbox();
        $lines = [
            "MCP FILE SANDBOX\n================",
            '',
            'Base folder: "' . $sandbox['baseFolder'] . '"',
            'Workspace upload folder: "' . $sandbox['uploadFolder'] . '"',
            'Workspace-scoped uploads: ' . ($sandbox['workspaceUploads'] ? 'enabled' : 'disabled'),
            'Current workspace: ' . ($sandbox['workspaceId'] > 0 ? (string)$sandbox['workspaceId'] : 'live'),
            '',
            'All MCP file tools are restricted to the base folder above.',
            '',
        ];

        $folderTarget = $this->fileSandboxService->resolveFolderTarget(null);
        return $this->browseResolvedFolder($folderTarget, $recursive, $lines, true);
    }

    private function browseFolder(string $path, bool $recursive, bool $allowMissing): CallToolResult
    {
        $folderTarget = $this->fileSandboxService->resolveFolderTarget($path);
        return $this->browseResolvedFolder($folderTarget, $recursive, [], $allowMissing);
    }

    /**
     * @param list<string> $lines
     * @param array{combinedIdentifier: string, storageUid: int, folderPath: string} $folderTarget
     */
    private function browseResolvedFolder(array $folderTarget, bool $recursive, array $lines, bool $allowMissing): CallToolResult
    {
        try {
            $folder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($folderTarget['combinedIdentifier']);
        } catch (FolderDoesNotExistException) {
            if ($allowMissing) {
                $lines[] = 'FOLDER: ' . $folderTarget['combinedIdentifier'];
                $lines[] = '';
                $lines[] = '(The sandbox folder does not exist yet. It will be created automatically on first write or upload.)';
                return new CallToolResult([new TextContent(implode("\n", $lines))]);
            }

            throw new ValidationException(["Folder not found: {$folderTarget['combinedIdentifier']}"]);
        } catch (\InvalidArgumentException) {
            throw new ValidationException([
                'Invalid folder path. Use a relative path inside the MCP file sandbox or an absolute combined identifier inside that sandbox.',
            ]);
        }

        $storage = $folder->getStorage();
        if (!$storage->isOnline() || !$storage->isBrowsable()) {
            throw new ValidationException(['Storage is not available or not browsable']);
        }

        $lines[] = \sprintf("FOLDER: %s (Storage %d: %s)\n", $folder->getIdentifier(), $storage->getUid(), $storage->getName());

        $subfolders = $folder->getSubfolders();
        if (!empty($subfolders)) {
            $lines[] = 'SUBFOLDERS:';
            foreach ($subfolders as $subfolder) {
                $combinedId = $storage->getUid() . ':' . $subfolder->getIdentifier();
                $lines[] = \sprintf('  [DIR] %s  -> "%s"', $subfolder->getName(), $combinedId);

                if ($recursive) {
                    $this->listSubfolderContents($subfolder, $storage, $lines, '    ');
                }
            }
            $lines[] = '';
        }

        $files = $folder->getFiles();
        if (!empty($files)) {
            $lines[] = \sprintf('FILES (%d):', \count($files));
            foreach ($files as $file) {
                $lines[] = $this->formatFileInfo($file);
            }
        } else {
            $lines[] = '(No files in this folder)';
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
            $lines[] = \sprintf('%s[DIR] %s  -> "%s"', $indent, $sub->getName(), $combinedId);
        }
        $fileCount = $folder->getFileCount();
        if ($fileCount > 0) {
            $lines[] = \sprintf('%s(%d files)', $indent, $fileCount);
        }
    }

    private function formatFileInfo(File $file): string
    {
        $size = GeneralUtility::formatSize($file->getSize(), 'si');
        $meta = '';

        if (str_starts_with($file->getMimeType(), 'image/')) {
            $props = $file->getProperties();
            $w = is_numeric($props['width'] ?? null) ? (int)$props['width'] : 0;
            $h = is_numeric($props['height'] ?? null) ? (int)$props['height'] : 0;
            if ($w > 0 && $h > 0) {
                $meta = \sprintf(' [%dx%d]', $w, $h);
            }
        }

        return \sprintf(
            '  %s  (%s, %s%s)  uid=%d',
            $file->getName(),
            $file->getMimeType(),
            $size,
            $meta,
            $file->getUid(),
        );
    }
}
