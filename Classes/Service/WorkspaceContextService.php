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
     * Creates a new workspace if none exists and user can create workspaces.
     */
    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        $currentWorkspace = $beUser->workspace;
        if ($currentWorkspace > 0) {
            return $currentWorkspace;
        }

        $workspaceId = $this->getFirstWritableWorkspace($beUser);

        if ($workspaceId === 0 && $this->canUserCreateWorkspaces($beUser)) {
            $workspaceId = $this->createMcpWorkspace($beUser);
        }

        $this->setWorkspaceContext($beUser, $workspaceId);

        return $workspaceId;
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
            if (!$this->localMode->allowsLiveWrites()) {
                throw new AccessDeniedException('live workspace (set localUnsafeMode=on or run inside DDEV)', 'switch');
            }
            $this->setWorkspaceContext($beUser, 0);
            return 0;
        }

        if ($workspaceId < 0) {
            throw new AccessDeniedException('workspace', 'switch');
        }

        $workspaceRecord = $beUser->checkWorkspace($workspaceId);
        if (!$workspaceRecord || !$this->hasWriteAccess($workspaceRecord)) {
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
        return in_array($access, ['admin', 'owner', 'member'], true);
    }

    private function getWorkspaceFromDatabase(BackendUserAuthentication $beUser): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $userId = (int)($beUser->user['uid'] ?? 0);

            $workspace = $queryBuilder
                ->select('uid')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('adminusers', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
                        $queryBuilder->expr()->like('adminusers', $queryBuilder->createNamedParameter('%,' . $userId . ',%')),
                        $queryBuilder->expr()->eq('members', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
                        $queryBuilder->expr()->like('members', $queryBuilder->createNamedParameter('%,' . $userId . ',%')),
                    ),
                )
                ->andWhere($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (is_array($workspace)) {
                $workspaceUid = $workspace['uid'] ?? 0;
                return is_numeric($workspaceUid) ? (int)$workspaceUid : 0;
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    private function canUserCreateWorkspaces(BackendUserAuthentication $beUser): bool
    {
        if ($beUser->isAdmin()) {
            return true;
        }

        return $beUser->check('modules', 'web_WorkspacesWorkspaces');
    }

    private function createMcpWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $realName = $beUser->user['realName'] ?? '';
            $username = $beUser->user['username'] ?? 'unknown_user';
            $workspaceTitle = 'MCP Workspace for ' . ($realName ?: $username);

            $workspaceData = [
                'pid' => 0,
                'title' => $workspaceTitle,
                'description' => 'Automatically created workspace for Model Context Protocol operations',
                'adminusers' => $beUser->user['uid'] ?? 0,
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

            if ($newUid && !$dataHandler->errorLog) {
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
