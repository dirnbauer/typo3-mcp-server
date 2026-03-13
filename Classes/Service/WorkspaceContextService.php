<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

final class WorkspaceContextService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Switch to the optimal workspace for the current user.
     * Creates a new workspace if none exists and user can create workspaces.
     */
    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        $currentWorkspace = $beUser->workspace ?? 0;
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
     * @throws AccessDeniedException if the user cannot access the workspace
     */
    public function switchToWorkspace(BackendUserAuthentication $beUser, int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            throw new AccessDeniedException('Cannot switch to the live workspace (id=0). All edits must go through a workspace.', 1741862400);
        }

        $workspaceRecord = $beUser->checkWorkspace($workspaceId);
        if (!$workspaceRecord || !$this->hasWriteAccess($workspaceRecord)) {
            throw new AccessDeniedException(
                sprintf('No write access to workspace %d. Available workspaces: %s', $workspaceId, $this->formatAvailableWorkspaces($beUser)),
                1741862401
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
        $currentWs = $beUser->workspace ?? 0;
        $result = [];

        try {
            $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
            $availableWorkspaces = $workspaceService->getAvailableWorkspaces();

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
                        ->where($qb->expr()->eq('uid', $qb->createNamedParameter($wsId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                        ->executeQuery()
                        ->fetchAssociative();
                    $description = $row['description'] ?? '';
                } catch (\Throwable) {
                }

                $result[] = [
                    'id' => $wsId,
                    'title' => $title,
                    'description' => $description,
                    'access' => $workspaceRecord['_ACCESS'] ?? 'unknown',
                    'active' => $wsId === $currentWs,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to list workspaces via WorkspaceService', ['exception' => $e]);
        }

        return $result;
    }

    protected function getFirstWritableWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
            $availableWorkspaces = $workspaceService->getAvailableWorkspaces();

            foreach ($availableWorkspaces as $workspaceId => $title) {
                if ($workspaceId > 0) {
                    $workspaceRecord = $beUser->checkWorkspace($workspaceId);
                    if ($workspaceRecord && $this->hasWriteAccess($workspaceRecord)) {
                        return $workspaceId;
                    }
                }
            }
        } catch (\Throwable $e) {
            return $this->getWorkspaceFromDatabase($beUser);
        }

        return 0;
    }

    protected function hasWriteAccess(array $workspaceRecord): bool
    {
        $access = $workspaceRecord['_ACCESS'] ?? '';
        return in_array($access, ['admin', 'owner', 'member'], true);
    }

    protected function getWorkspaceFromDatabase(BackendUserAuthentication $beUser): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $userId = (int)($beUser->user['uid'] ?? 0);

            $workspace = $queryBuilder
                ->select('uid')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('adminusers', $queryBuilder->createNamedParameter($userId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                        $queryBuilder->expr()->like('adminusers', $queryBuilder->createNamedParameter('%,' . $userId . ',%')),
                        $queryBuilder->expr()->eq('members', $queryBuilder->createNamedParameter($userId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                        $queryBuilder->expr()->like('members', $queryBuilder->createNamedParameter('%,' . $userId . ',%'))
                    )
                )
                ->andWhere($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($workspace) {
                return (int)$workspace['uid'];
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    protected function canUserCreateWorkspaces(BackendUserAuthentication $beUser): bool
    {
        if ($beUser->isAdmin()) {
            return true;
        }

        return $beUser->check('modules', 'web_WorkspacesWorkspaces');
    }

    protected function createMcpWorkspace(BackendUserAuthentication $beUser): int
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
                'publish_access' => 1,
                'stagechg_notification' => 0,
                'publish_time' => 0,
            ];

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->admin = true;
            $dataHandler->bypassWorkspaceRestrictions = true;

            $newId = 'NEW' . uniqid();
            $dataHandler->start(['sys_workspace' => [$newId => $workspaceData]], []);
            $dataHandler->process_datamap();

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
        return $GLOBALS['BE_USER']->workspace ?? 0;
    }

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
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceId, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();

            if ($workspace) {
                return [
                    'id' => (int)$workspace['uid'],
                    'title' => $workspace['title'],
                    'description' => $workspace['description'],
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
            $workspaces
        ));
    }
}