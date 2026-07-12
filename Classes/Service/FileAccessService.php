<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceInterface;

/**
 * Central authorization guard for file reads outside SQL search queries.
 *
 * The MCP sandbox limits where tools may operate, while TYPO3 file mounts
 * limit what the authenticated editor may see. Both boundaries are required;
 * local mode may relax only the sandbox.
 */
final readonly class FileAccessService
{
    public function __construct(
        private TableAccessService $tableAccessService,
    ) {}

    public function requireReadableBackendUser(): BackendUserAuthentication
    {
        $this->tableAccessService->validateTableAccess('sys_file', 'read');
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new AccessDeniedException('backend user context', 'read files');
        }

        // Initializes TYPO3's user-specific storage permission aspects before
        // ResourceFactory resolves a folder or file from the shared repository.
        $backendUser->getFileStorages();
        return $backendUser;
    }

    public function assertResourceReadable(ResourceInterface $resource): void
    {
        $backendUser = $this->requireReadableBackendUser();
        if ($resource instanceof ProcessedFile) {
            $this->assertResourceReadable($resource->getOriginalFile());
            return;
        }

        $storage = $resource->getStorage();
        $storages = $backendUser->getFileStorages();
        if (!isset($storages[$storage->getUid()])
            || !$storage->isWithinFileMountBoundaries($resource)
            || !$this->isIdentifierWithinFileMounts(
                $backendUser,
                $storage->getUid(),
                $resource->getIdentifier(),
            )
        ) {
            throw new AccessDeniedException('file or folder outside the backend file mount', 'read');
        }
    }

    public function assertCombinedIdentifierReadable(string $combinedIdentifier): void
    {
        $backendUser = $this->requireReadableBackendUser();
        if (preg_match('#^(\d+):(/.*)$#', $combinedIdentifier, $matches) !== 1) {
            throw new AccessDeniedException('invalid FAL identifier', 'read');
        }

        $storageUid = (int)$matches[1];
        $identifier = preg_replace('#/+#', '/', $matches[2]) ?? $matches[2];
        $storages = $backendUser->getFileStorages();
        if (!isset($storages[$storageUid])
            || !$this->isIdentifierWithinFileMounts($backendUser, $storageUid, $identifier)
        ) {
            throw new AccessDeniedException('file or folder outside the backend file mount', 'read');
        }
    }

    private function isIdentifierWithinFileMounts(
        BackendUserAuthentication $backendUser,
        int $storageUid,
        string $identifier,
    ): bool {
        if ($backendUser->isAdmin()) {
            return true;
        }

        $identifier = '/' . ltrim($identifier, '/');
        foreach ($backendUser->getFileMountRecords() as $mount) {
            if (!is_array($mount)) {
                continue;
            }
            $combinedIdentifier = is_string($mount['identifier'] ?? null)
                ? $mount['identifier']
                : '';
            if (preg_match('#^(\d+):(/.*)$#', $combinedIdentifier, $matches) !== 1) {
                continue;
            }
            if ((int)$matches[1] !== $storageUid) {
                continue;
            }

            $trimmedMountPath = trim($matches[2], '/');
            $mountPath = $trimmedMountPath === '' ? '/' : '/' . $trimmedMountPath . '/';
            $resourcePath = '/' . ltrim($identifier, '/');
            if (str_starts_with($resourcePath, $mountPath)) {
                return true;
            }
        }

        return false;
    }
}
