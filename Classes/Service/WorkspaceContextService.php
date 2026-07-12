<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

final readonly class WorkspaceContextService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private LoggerInterface $logger,
        private WorkspaceService $workspaceService,
        private LocalModeService $localMode,
    ) {}

    public function localModeEnabled(): bool
    {
        return $this->localMode->isLocalMode();
    }

    /**
     * Switch to the optimal workspace for the current user.
     *
     * In strict/production mode, selects the first writable draft workspace or
     * creates an MCP workspace when none exist. In local mode
     * ({@see LocalModeService::allowsLiveWrites()}), defaults to live (0) so
     * DDEV / Development workflows edit published content unless the client
     * passes an explicit draft ``workspace_id``.
     */
    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        $currentWorkspace = $beUser->workspace;
        if ($currentWorkspace > 0 && $this->canWriteWorkspace($beUser, $currentWorkspace)) {
            $this->setWorkspaceContext($beUser, $currentWorkspace);

            return $currentWorkspace;
        }

        if ($currentWorkspace > 0) {
            $this->logger->warning('Ignoring inaccessible or non-writable preselected workspace', [
                'userId' => is_numeric($beUser->user['uid'] ?? null) ? (int)$beUser->user['uid'] : 0,
                'workspaceId' => $currentWorkspace,
            ]);
        }

        if ($this->localMode->allowsLiveWrites() && $this->canWriteWorkspace($beUser, 0)) {
            $this->setWorkspaceContext($beUser, 0);

            return 0;
        }

        $workspaceId = $this->getFirstWritableWorkspace($beUser);

        if ($workspaceId === 0 && $this->canProvisionWorkspace($beUser)) {
            $workspaceId = $this->createMcpWorkspace($beUser);
        }

        if ($workspaceId <= 0 || !$this->canWriteWorkspace($beUser, $workspaceId)) {
            throw new AccessDeniedException('writable workspace', 'select');
        }

        $this->setWorkspaceContext($beUser, $workspaceId);

        return $workspaceId;
    }

    /**
     * Establish a workspace overlay for authentication and read-only tools
     * without requiring the user to own a writable draft. An explicit draft
     * still has to be accessible; an inaccessible preselected context from an
     * HTTP header is ignored and safely falls back to live reads.
     */
    public function switchToReadWorkspace(
        BackendUserAuthentication $beUser,
        ?int $requestedWorkspaceId = null,
    ): int {
        $workspaceId = $requestedWorkspaceId ?? $beUser->workspace;
        if ($workspaceId <= 0) {
            $this->setLiveReadContext($beUser);
            return 0;
        }

        if ($this->canAccessWorkspace($beUser, $workspaceId)) {
            $this->setWorkspaceContext($beUser, $workspaceId);
            return $workspaceId;
        }

        if ($requestedWorkspaceId !== null) {
            throw new AccessDeniedException(sprintf('workspace %d', $workspaceId), 'read');
        }

        $this->logger->warning('Ignoring inaccessible preselected read workspace', [
            'userId' => is_numeric($beUser->user['uid'] ?? null) ? (int)$beUser->user['uid'] : 0,
            'workspaceId' => $workspaceId,
        ]);
        $this->setLiveReadContext($beUser);
        return 0;
    }

    private function setLiveReadContext(BackendUserAuthentication $beUser): void
    {
        // setTemporaryWorkspace(0) checks live *edit* permission. Read-only
        // authentication still needs a coherent live overlay without gaining
        // any write rights, so update only the workspace state/aspect here.
        $beUser->workspace = 0;
        $beUser->user['workspace_id'] = 0;
        $this->context->setAspect('workspace', new WorkspaceAspect(0));
    }

    /**
     * Switch to an explicitly requested workspace after validating access.
     *
     * Workspace 0 (live) is rejected unless {@see LocalModeService::allowsLiveWrites()}
     * returns true (DDEV / development context). The default safety net keeps writes
     * staged in a workspace even when the caller passes `workspace_id: 0` explicitly.
     *
     * @throws AccessDeniedException if the user cannot access the workspace
     */
    public function switchToWorkspace(BackendUserAuthentication $beUser, int $workspaceId): int
    {
        if ($workspaceId === 0) {
            if (!$this->localMode->allowsLiveWrites() || !$this->canWriteWorkspace($beUser, 0)) {
                throw new AccessDeniedException('live workspace (set localUnsafeMode=on or run inside DDEV)', 'switch');
            }
            $this->setWorkspaceContext($beUser, 0);
            return 0;
        }

        if ($workspaceId < 0) {
            throw new AccessDeniedException('workspace', 'switch');
        }

        if (!$this->canWriteWorkspace($beUser, $workspaceId)) {
            throw new AccessDeniedException(
                sprintf('workspace %d (%s)', $workspaceId, $this->formatAvailableWorkspaces($beUser)),
                'write',
            );
        }

        $this->setWorkspaceContext($beUser, $workspaceId);

        return $workspaceId;
    }

    /**
     * @return list<array{id: int, title: string, description: string, access: string, active: bool}>
     */
    public function getAvailableWorkspaces(BackendUserAuthentication $beUser): array
    {
        $currentWs = $beUser->workspace;
        $result = [];

        try {
            $availableWorkspaces = $this->workspaceService->getAvailableWorkspaces();

            foreach ($availableWorkspaces as $wsId => $title) {
                if ($wsId <= 0) {
                    continue;
                }
                $workspaceRecord = $beUser->checkWorkspace($wsId);
                if (!$workspaceRecord) {
                    continue;
                }

                $description = '';
                try {
                    $qb = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
                    $row = $qb->select('description')
                        ->from('sys_workspace')
                        ->where($qb->expr()->eq('uid', $qb->createNamedParameter($wsId, Connection::PARAM_INT)))
                        ->executeQuery()
                        ->fetchAssociative();
                    $description = is_array($row) && is_string($row['description'] ?? null) ? $row['description'] : '';
                } catch (\Throwable) {
                }

                $result[] = [
                    'id' => is_int($wsId) ? $wsId : (int)$wsId,
                    'title' => is_string($title) ? $title : '',
                    'description' => $description,
                    'access' => is_string($workspaceRecord['_ACCESS'] ?? null) ? $workspaceRecord['_ACCESS'] : 'unknown',
                    'active' => $wsId === $currentWs,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list workspaces via WorkspaceService', ['exception' => $e]);
        }

        return $result;
    }

    /**
     * If a **live** record (uid) has a draft/version row in the given workspace, return that row's uid.
     * Necessary when {@see \TYPO3\CMS\Backend\Utility\BackendUtility::workspaceOL()} does not rewrite `uid` on
     * the overlay array, so callers would otherwise attach `sys_file_reference` to the live id while
     * DataHandler updates the version row (empty file fields in workspace / frontend).
     */
    public function findWorkspaceVersionRowUid(string $table, int $liveUid, int $workspaceId): ?int
    {
        if ($workspaceId <= 0 || $liveUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || !is_numeric($row['uid'] ?? null)) {
            return null;
        }
        $uid = (int)$row['uid'];
        if ($uid <= 0) {
            return null;
        }

        return $uid === $liveUid ? null : $uid;
    }

    /**
     * Append a freshly created site root page (its "starting point") to the
     * page-tree mounts of every workspace that is *restricted* to specific page
     * trees, so the new website can be staged in those workspaces.
     *
     * TYPO3 treats an empty ``sys_workspace.db_mountpoints`` — or one that mounts
     * the page-tree root (uid 0) — as "no restriction": those workspaces already
     * reach every tree and are intentionally left untouched (appending a single
     * id would otherwise *restrict* them to that one tree, hiding all other
     * sites). Only workspaces already limited to specific trees gain the new root
     * page.
     *
     * This governs *staging scope*, not *who may edit* — content-edit permission
     * is granted via the dedicated editor group ({@see SiteEditorGroupService}).
     *
     * ``sys_workspace`` is not workspace-versioned; the update runs through
     * DataHandler as admin in the live workspace, mirroring {@see createMcpWorkspace()}.
     *
     * @return array{updated: list<array{id: int, title: string, mountpoints: string}>, skipped: array<string, int>}
     */
    public function addRootPageToWorkspaceMountpoints(int $rootPageId, ?BackendUserAuthentication $beUser = null): array
    {
        $report = ['updated' => [], 'skipped' => []];
        if ($rootPageId <= 0) {
            return $report;
        }

        $beUser ??= ($GLOBALS['BE_USER'] ?? null);
        if (!$beUser instanceof BackendUserAuthentication) {
            return $report;
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $queryBuilder->getRestrictions()->removeAll();
            $workspaces = $queryBuilder
                ->select('uid', 'title', 'db_mountpoints')
                ->from('sys_workspace')
                ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load workspaces for mountpoint sync', ['exception' => $e]);
            return $report;
        }

        $datamap = [];
        foreach ($workspaces as $workspace) {
            $uid = is_numeric($workspace['uid'] ?? null) ? (int)$workspace['uid'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $title = is_string($workspace['title'] ?? null) ? $workspace['title'] : '';
            $mounts = $this->parseMountpoints($workspace['db_mountpoints'] ?? '');

            // Empty or root-mounted (uid 0) workspaces already reach every page tree.
            if ($mounts === [] || in_array(0, $mounts, true)) {
                $report['skipped']['unrestricted'] = ($report['skipped']['unrestricted'] ?? 0) + 1;
                continue;
            }
            if (in_array($rootPageId, $mounts, true)) {
                $report['skipped']['alreadyMounted'] = ($report['skipped']['alreadyMounted'] ?? 0) + 1;
                continue;
            }

            $value = implode(',', [...$mounts, $rootPageId]);
            $datamap[$uid] = $value;
            $report['updated'][] = ['id' => $uid, 'title' => $title, 'mountpoints' => $value];
        }

        if ($datamap !== []) {
            $this->writeWorkspaceMountpoints($beUser, $datamap);
        }

        return $report;
    }

    /**
     * @return list<int>
     */
    private function parseMountpoints(mixed $value): array
    {
        if (!is_string($value) && !is_int($value)) {
            return [];
        }

        $out = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $out[] = (int)$part;
        }

        return $out;
    }

    /**
     * Persist new db_mountpoints values for the given workspaces via DataHandler,
     * forcing the admin/live context the way {@see createMcpWorkspace()} does
     * because sys_workspace is not workspace-versioned.
     *
     * Best-effort: a DataHandler error is logged but does not abort site creation.
     *
     * @param array<int, string> $datamap workspace uid => new db_mountpoints CSV
     */
    private function writeWorkspaceMountpoints(BackendUserAuthentication $beUser, array $datamap): void
    {
        $data = ['sys_workspace' => []];
        foreach ($datamap as $uid => $mountpoints) {
            $data['sys_workspace'][$uid] = ['db_mountpoints' => $mountpoints];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $originalAdmin = $beUser->user['admin'] ?? 0;
        $originalWorkspace = $beUser->workspace;
        $originalWorkspaceId = $beUser->user['workspace_id'] ?? 0;

        $beUser->user['admin'] = 1;
        $beUser->workspace = 0;
        $beUser->user['workspace_id'] = 0;

        try {
            $dataHandler->start($data, []);
            $dataHandler->process_datamap();
        } finally {
            $beUser->user['admin'] = $originalAdmin;
            $beUser->workspace = $originalWorkspace;
            $beUser->user['workspace_id'] = $originalWorkspaceId;
        }

        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('Failed to update workspace mountpoints', ['errors' => $dataHandler->errorLog]);
        }
    }

    private function getFirstWritableWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $availableWorkspaces = $this->workspaceService->getAvailableWorkspaces();

            foreach ($availableWorkspaces as $workspaceId => $title) {
                if ($workspaceId > 0) {
                    $workspaceRecord = $beUser->checkWorkspace($workspaceId);
                    if ($workspaceRecord && $this->hasWriteAccess($workspaceRecord)) {
                        return $workspaceId;
                    }
                }
            }
        } catch (\Throwable) {
            return $this->getWorkspaceFromDatabase($beUser);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $workspaceRecord
     */
    private function hasWriteAccess(array $workspaceRecord): bool
    {
        $access = is_string($workspaceRecord['_ACCESS'] ?? null) ? $workspaceRecord['_ACCESS'] : '';
        return in_array($access, ['admin', 'owner', 'member', 'online'], true);
    }

    private function canWriteWorkspace(BackendUserAuthentication $beUser, int $workspaceId): bool
    {
        try {
            $workspaceRecord = $beUser->checkWorkspace($workspaceId);
        } catch (\Throwable) {
            return false;
        }

        if (!$workspaceRecord) {
            return false;
        }

        return $this->hasWriteAccess($workspaceRecord);
    }

    private function canAccessWorkspace(BackendUserAuthentication $beUser, int $workspaceId): bool
    {
        try {
            return (bool)$beUser->checkWorkspace($workspaceId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function getWorkspaceFromDatabase(BackendUserAuthentication $beUser): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $workspaces = $queryBuilder
                ->select('uid')
                ->from('sys_workspace')
                ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($workspaces as $workspace) {
                $workspaceUid = $workspace['uid'] ?? 0;
                $workspaceId = is_numeric($workspaceUid) ? (int)$workspaceUid : 0;
                if ($workspaceId > 0 && $this->canWriteWorkspace($beUser, $workspaceId)) {
                    return $workspaceId;
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private function canProvisionWorkspace(BackendUserAuthentication $beUser): bool
    {
        // Backend module visibility only controls access to TYPO3's Workspaces
        // UI. It is not an authorization boundary for editing records in an
        // already assigned workspace. MCP provisions an isolated draft as a
        // safety mechanism; normal table, field, page and DataHandler checks
        // still decide whether the requested record operation is permitted.
        return is_numeric($beUser->user['uid'] ?? null) && (int)$beUser->user['uid'] > 0;
    }

    private function createMcpWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $rawUserId = $beUser->user['uid'] ?? null;
            if (!is_numeric($rawUserId) || (int)$rawUserId <= 0) {
                return 0;
            }
            $userId = (int)$rawUserId;

            $realName = $beUser->user['realName'] ?? '';
            $username = $beUser->user['username'] ?? 'unknown_user';
            $workspaceTitle = 'MCP Workspace for ' . ($realName ?: $username);

            $workspaceData = [
                'pid' => 0,
                'title' => $workspaceTitle,
                'description' => 'Automatically created workspace for Model Context Protocol operations',
                // TYPO3 group fields store typed relation tokens. A bare UID
                // is never recognized by BackendUserAuthentication::checkWorkspace().
                'adminusers' => 'be_users_' . $userId,
                'members' => '',
                'db_mountpoints' => '',
                'file_mountpoints' => '',
                'publish_access' => 0,
                'stagechg_notification' => 0,
                'publish_time' => 0,
            ];

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $originalAdmin = $beUser->user['admin'] ?? 0;
            $originalWorkspace = $beUser->workspace;
            $originalWorkspaceId = $beUser->user['workspace_id'] ?? 0;

            $beUser->user['admin'] = 1;
            $beUser->workspace = 0;
            $beUser->user['workspace_id'] = 0;

            $newId = 'NEW' . uniqid();
            try {
                $dataHandler->start(['sys_workspace' => [$newId => $workspaceData]], []);
                $dataHandler->process_datamap();
            } finally {
                $beUser->user['admin'] = $originalAdmin;
                $beUser->workspace = $originalWorkspace;
                $beUser->user['workspace_id'] = $originalWorkspaceId;
            }

            $newUid = $dataHandler->substNEWwithIDs[$newId] ?? null;

            if (is_numeric($newUid) && (int)$newUid > 0 && $dataHandler->errorLog === []) {
                return (int)$newUid;
            }
        } catch (\Throwable $e) {
            $this->logger->error('MCP Workspace creation failed', ['exception' => $e]);
        }

        return 0;
    }

    public function setWorkspaceContext(BackendUserAuthentication $beUser, int $workspaceId): void
    {
        $beUser->setTemporaryWorkspace($workspaceId);
        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    public function getCurrentWorkspace(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser->workspace : 0;
    }

    /**
     * @return array{id: int, title: string, description: string, is_live: bool}
     */
    public function getWorkspaceInfo(): array
    {
        $workspaceId = $this->getCurrentWorkspace();

        if ($workspaceId === 0) {
            return [
                'id' => 0,
                'title' => 'Live',
                'description' => 'Live workspace - changes are immediately public',
                'is_live' => true,
            ];
        }

        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $workspace = $queryBuilder
                ->select('uid', 'title', 'description')
                ->from('sys_workspace')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($workspace)) {
                $workspaceUid = $workspace['uid'] ?? $workspaceId;
                return [
                    'id' => is_numeric($workspaceUid) ? (int)$workspaceUid : $workspaceId,
                    'title' => is_string($workspace['title'] ?? null) ? $workspace['title'] : 'Unknown Workspace',
                    'description' => is_string($workspace['description'] ?? null) ? $workspace['description'] : '',
                    'is_live' => false,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'id' => $workspaceId,
            'title' => 'Unknown Workspace',
            'description' => 'Workspace information not available',
            'is_live' => false,
        ];
    }

    private function formatAvailableWorkspaces(BackendUserAuthentication $beUser): string
    {
        $workspaces = $this->getAvailableWorkspaces($beUser);
        if (empty($workspaces)) {
            return '(none)';
        }

        return implode(', ', array_map(
            static fn(array $ws): string => sprintf('%d (%s)', $ws['id'], $ws['title']),
            $workspaces,
        ));
    }
}
