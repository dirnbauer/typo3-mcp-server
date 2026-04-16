<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class McpFileSandboxService
{
    private const DEFAULT_BASE_FOLDER = '1:/mcp/';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private WorkspaceContextService $workspaceContextService,
    ) {}

    /**
     * @return array{
     *     baseFolder: string,
     *     uploadFolder: string,
     *     workspaceId: int,
     *     workspaceUploads: bool
     * }
     */
    public function describeSandbox(): array
    {
        $baseFolder = $this->getBaseFolderIdentifier();
        $workspaceId = $this->workspaceContextService->getCurrentWorkspace();

        return [
            'baseFolder' => $baseFolder,
            'uploadFolder' => $this->buildUploadFolderIdentifier($workspaceId),
            'workspaceId' => $workspaceId,
            'workspaceUploads' => $this->useWorkspaceUploadFolders(),
        ];
    }

    /**
     * @return array{
     *     combinedIdentifier: string,
     *     storageUid: int,
     *     folderPath: string,
     *     fileName: string,
     *     baseFolder: string,
     *     workspaceId: int
     * }
     */
    public function resolveFileTarget(string $path): array
    {
        $base = $this->parseConfiguredBaseFolder();
        $relativePath = $this->resolveRelativeFilePath($path, false);

        return $this->buildFileTargetFromRelativePath($relativePath, $base['storageUid'], $base['folderPath']);
    }

    /**
     * @return array{
     *     combinedIdentifier: string,
     *     storageUid: int,
     *     folderPath: string,
     *     fileName: string,
     *     baseFolder: string,
     *     workspaceId: int,
     *     uploadFolder: string
     * }
     */
    public function resolveUploadTarget(string $path): array
    {
        $base = $this->parseConfiguredBaseFolder();
        $workspaceId = $this->resolveWorkspaceForUpload();
        $relativePath = $this->resolveRelativeFilePath($path, true, $workspaceId);
        $defaultFolder = $this->getUploadFolderPath($workspaceId, $base['folderPath']);
        $target = $this->buildFileTargetFromRelativePath($relativePath, $base['storageUid'], $defaultFolder);
        $target['uploadFolder'] = $base['storageUid'] . ':' . $defaultFolder;

        return $target;
    }

    /**
     * @return array{
     *     combinedIdentifier: string,
     *     storageUid: int,
     *     folderPath: string,
     *     baseFolder: string,
     *     workspaceId: int,
     *     uploadFolder: string
     * }
     */
    public function resolveFolderTarget(?string $path): array
    {
        $base = $this->parseConfiguredBaseFolder();
        $workspaceId = $this->workspaceContextService->getCurrentWorkspace();

        if ($path === null || trim($path) === '') {
            $folderPath = $base['folderPath'];
        } elseif ($this->isCombinedIdentifier($path)) {
            $parsed = $this->parseAbsoluteFolderIdentifier($path);
            $this->assertWithinBaseFolder($parsed['storageUid'], $parsed['folderPath'], $base);
            $folderPath = $parsed['folderPath'];
        } else {
            $relativePath = $this->sanitizeRelativeFolderPath($path);
            $folderPath = $this->joinFolderSegments($base['folderPath'], $relativePath);
        }

        return [
            'combinedIdentifier' => $base['storageUid'] . ':' . $folderPath,
            'storageUid' => $base['storageUid'],
            'folderPath' => $folderPath,
            'baseFolder' => $base['storageUid'] . ':' . $base['folderPath'],
            'workspaceId' => $workspaceId,
            'uploadFolder' => $this->buildUploadFolderIdentifier($workspaceId),
        ];
    }

    public function assertFileAllowed(File|ProcessedFile $file): void
    {
        $base = $this->parseConfiguredBaseFolder();
        $identifier = $file->getCombinedIdentifier();

        if (!$this->isCombinedIdentifier($identifier)) {
            throw new ValidationException(['The configured MCP file sandbox could not validate the file identifier.']);
        }

        $parsed = $this->parseAbsoluteFileIdentifier($identifier);
        $fullPath = $parsed['folderPath'] . $parsed['fileName'];
        $this->assertWithinBaseFolder($parsed['storageUid'], $fullPath, $base);
    }

    public function getBaseFolderIdentifier(): string
    {
        $base = $this->parseConfiguredBaseFolder();
        return $base['storageUid'] . ':' . $base['folderPath'];
    }

    public function buildStoredUploadFileName(string $requestedFileName): string
    {
        $requestedFileName = trim($requestedFileName);
        $extension = strtolower(pathinfo($requestedFileName, PATHINFO_EXTENSION));
        $baseName = pathinfo($requestedFileName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?? 'upload';
        $baseName = trim($baseName, '-_.');
        $baseName = $baseName !== '' ? $baseName : 'upload';
        $baseName = substr($baseName, 0, 60);

        $randomSuffix = bin2hex(random_bytes(8));

        return $extension !== ''
            ? sprintf('%s-%s.%s', $baseName, $randomSuffix, $extension)
            : sprintf('%s-%s', $baseName, $randomSuffix);
    }

    /**
     * @return array{storageUid: int, folderPath: string}
     */
    private function parseConfiguredBaseFolder(): array
    {
        $configured = $this->getRawConfigValue('fileSandboxRoot');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = self::DEFAULT_BASE_FOLDER;
        }

        $configured = str_replace('\\', '/', trim($configured));
        if (!$this->isCombinedIdentifier($configured)) {
            $configured = trim($configured, '/');
            if (str_starts_with($configured, 'fileadmin/')) {
                $configured = substr($configured, 10);
            }
            $configured = $configured !== '' ? '1:/' . $configured . '/' : self::DEFAULT_BASE_FOLDER;
        }

        $parsed = $this->parseAbsoluteFolderIdentifier($configured);

        return [
            'storageUid' => $parsed['storageUid'],
            'folderPath' => $parsed['folderPath'],
        ];
    }

    private function useWorkspaceUploadFolders(): bool
    {
        $configured = $this->getRawConfigValue('workspaceUploadSubfolders');
        if (is_bool($configured)) {
            return $configured;
        }
        if (is_int($configured)) {
            return $configured === 1;
        }
        if (is_string($configured)) {
            return in_array(strtolower(trim($configured)), ['1', 'true', 'yes', 'on'], true);
        }

        return true;
    }

    private function resolveWorkspaceForUpload(): int
    {
        if (!$this->useWorkspaceUploadFolders()) {
            return $this->workspaceContextService->getCurrentWorkspace();
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        return $this->workspaceContextService->switchToOptimalWorkspace($backendUser);
    }

    private function buildUploadFolderIdentifier(int $workspaceId): string
    {
        $base = $this->parseConfiguredBaseFolder();
        return $base['storageUid'] . ':' . $this->getUploadFolderPath($workspaceId, $base['folderPath']);
    }

    private function getUploadFolderPath(int $workspaceId, string $baseFolderPath): string
    {
        if (!$this->useWorkspaceUploadFolders() || $workspaceId <= 0) {
            return $baseFolderPath;
        }

        return $this->joinFolderSegments($baseFolderPath, 'workspaces/ws-' . $workspaceId . '/');
    }

    /**
     * @param array{storageUid: int, folderPath: string} $base
     */
    private function assertWithinBaseFolder(int $storageUid, string $fullPath, array $base): void
    {
        if ($storageUid !== $base['storageUid']) {
            throw new ValidationException([
                sprintf(
                    'File access is restricted to the configured MCP file sandbox "%s".',
                    $base['storageUid'] . ':' . $base['folderPath'],
                ),
            ]);
        }

        if (!str_starts_with($fullPath, $base['folderPath'])) {
            throw new ValidationException([
                sprintf(
                    'File access is restricted to the configured MCP file sandbox "%s".',
                    $base['storageUid'] . ':' . $base['folderPath'],
                ),
            ]);
        }
    }

    /**
     * @return array{
     *     combinedIdentifier: string,
     *     storageUid: int,
     *     folderPath: string,
     *     fileName: string,
     *     baseFolder: string,
     *     workspaceId: int
     * }
     */
    private function buildFileTargetFromRelativePath(string $relativePath, int $storageUid, string $baseFolderPath): array
    {
        $parts = $this->sanitizeRelativeFilePath($relativePath);
        $folderPath = $this->joinFolderSegments($baseFolderPath, $parts['folderPath']);
        $combinedIdentifier = $storageUid . ':' . $folderPath . $parts['fileName'];

        return [
            'combinedIdentifier' => $combinedIdentifier,
            'storageUid' => $storageUid,
            'folderPath' => $folderPath,
            'fileName' => $parts['fileName'],
            'baseFolder' => $this->getBaseFolderIdentifier(),
            'workspaceId' => $this->workspaceContextService->getCurrentWorkspace(),
        ];
    }

    private function resolveRelativeFilePath(string $path, bool $workspaceAware, ?int $workspaceId = null): string
    {
        $base = $this->parseConfiguredBaseFolder();

        if ($this->isCombinedIdentifier($path)) {
            $parsed = $this->parseAbsoluteFileIdentifier($path);
            $fullPath = $parsed['folderPath'] . $parsed['fileName'];
            $this->assertWithinBaseFolder($parsed['storageUid'], $fullPath, $base);

            if ($workspaceAware && $workspaceId !== null) {
                $workspaceFolderPath = $this->getUploadFolderPath($workspaceId, $base['folderPath']);
                if (!str_starts_with($fullPath, $workspaceFolderPath)) {
                    throw new ValidationException([
                        sprintf(
                            'Workspace uploads must stay inside "%s".',
                            $base['storageUid'] . ':' . $workspaceFolderPath,
                        ),
                    ]);
                }
            }

            return substr($fullPath, strlen($base['folderPath']));
        }

        return $path;
    }

    /**
     * @return array{folderPath: string, fileName: string}
     */
    private function sanitizeRelativeFilePath(string $path): array
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $segments = GeneralUtility::trimExplode('/', $path, true);

        if ($segments === []) {
            throw new ValidationException(['Path must include a filename.']);
        }

        $fileName = array_pop($segments);
        if (!is_string($fileName) || $fileName === '' || in_array($fileName, ['.', '..'], true)) {
            throw new ValidationException(['Path must include a valid filename.']);
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new ValidationException(['Directory traversal is not allowed in file paths.']);
            }
        }

        return [
            'folderPath' => $segments !== [] ? implode('/', $segments) . '/' : '',
            'fileName' => $fileName,
        ];
    }

    private function sanitizeRelativeFolderPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = GeneralUtility::trimExplode('/', $path, true);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new ValidationException(['Directory traversal is not allowed in folder paths.']);
            }
        }

        return implode('/', $segments) . '/';
    }

    private function joinFolderSegments(string $baseFolderPath, string $relativeFolderPath): string
    {
        $baseFolderPath = '/' . trim($baseFolderPath, '/') . '/';
        if ($relativeFolderPath === '') {
            return $baseFolderPath;
        }

        return $baseFolderPath . trim($relativeFolderPath, '/') . '/';
    }

    private function isCombinedIdentifier(string $path): bool
    {
        return preg_match('#^\d+:/#', $path) === 1;
    }

    /**
     * @return array{storageUid: int, folderPath: string, fileName: string}
     */
    private function parseAbsoluteFileIdentifier(string $identifier): array
    {
        if (!preg_match('#^(\d+):(/.+)$#', $identifier, $matches)) {
            throw new ValidationException([
                'Invalid path format. Use "<storageId>:/<folder/path/filename.ext>" or a relative path inside the MCP file sandbox.',
            ]);
        }

        $storageUid = (int)$matches[1];
        $fullPath = str_replace('\\', '/', $matches[2]);
        $fullPath = preg_replace('#/+#', '/', $fullPath) ?? $fullPath;

        $lastSlash = strrpos($fullPath, '/');
        if ($lastSlash === false || $lastSlash === strlen($fullPath) - 1) {
            throw new ValidationException(['Path must include a valid filename.']);
        }

        $folderPath = substr($fullPath, 0, $lastSlash + 1);
        $fileName = substr($fullPath, $lastSlash + 1);

        if ($fileName === '') {
            throw new ValidationException(['Path must include a valid filename.']);
        }

        $this->sanitizeRelativeFolderPath($folderPath);
        if ($fileName === '.' || $fileName === '..') {
            throw new ValidationException(['Path must include a valid filename.']);
        }

        return [
            'storageUid' => $storageUid,
            'folderPath' => '/' . trim($folderPath, '/') . '/',
            'fileName' => $fileName,
        ];
    }

    /**
     * @return array{storageUid: int, folderPath: string}
     */
    private function parseAbsoluteFolderIdentifier(string $identifier): array
    {
        if (!preg_match('#^(\d+):(/.*)$#', $identifier, $matches)) {
            throw new ValidationException([
                'Invalid folder path format. Use "<storageId>:/<folder/path/>" or a relative path inside the MCP file sandbox.',
            ]);
        }

        $storageUid = (int)$matches[1];
        $folderPath = str_replace('\\', '/', $matches[2]);
        $folderPath = preg_replace('#/+#', '/', $folderPath) ?? $folderPath;
        $folderPath = '/' . trim($folderPath, '/') . '/';

        $this->sanitizeRelativeFolderPath($folderPath);

        return [
            'storageUid' => $storageUid,
            'folderPath' => $folderPath,
        ];
    }

    private function getRawConfigValue(string $key): mixed
    {
        try {
            $configuration = $this->extensionConfiguration->get('mcp_server');
            return is_array($configuration) ? ($configuration[$key] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
