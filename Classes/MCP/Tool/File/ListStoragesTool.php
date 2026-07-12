<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Tool for listing available file storages in TYPO3
 */
final class ListStoragesTool extends AbstractRecordTool
{
    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'List all available file storages in TYPO3 that can be browsed. Returns storage UIDs, names, and capabilities (public, writable, default).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'includeOffline' => [
                        'type' => 'boolean',
                        'description' => 'Include offline storages in the listing (default: false)',
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
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        $this->ensureTableAccess('sys_file', 'read');
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new AccessDeniedException('backend user context', 'list file storages');
        }

        $includeOffline = (bool)($params['includeOffline'] ?? false);
        $storages = $backendUser->getFileStorages();

        $lines = [];
        $lines[] = 'FILE STORAGES';
        $lines[] = '=============';
        $lines[] = '';

        $count = 0;
        foreach ($storages as $storage) {
            if (!$storage->isBrowsable()) {
                continue;
            }
            if (!$includeOffline && !$storage->isOnline()) {
                continue;
            }

            $count++;
            $flags = [];
            if ($storage->isPublic()) {
                $flags[] = 'public';
            }
            if ($storage->isWritable()) {
                $flags[] = 'writable';
            }
            if ($storage->isDefault()) {
                $flags[] = 'default';
            }
            if (!$storage->isOnline()) {
                $flags[] = 'OFFLINE';
            }

            $rootFolder = $storage->getRootLevelFolder(true);

            $lines[] = sprintf(
                'Storage %d: %s [%s]',
                $storage->getUid(),
                $storage->getName(),
                implode(', ', $flags)
            );
            $lines[] = sprintf(
                '  Root: %d:%s',
                $storage->getUid(),
                $rootFolder->getIdentifier()
            );
            $lines[] = '';
        }

        if ($count === 0) {
            $lines[] = 'No browsable storages found.';
        } else {
            $lines[] = sprintf('Total: %d storage(s)', $count);
        }

        return $this->createSuccessResult(implode("\n", $lines));
    }
}
