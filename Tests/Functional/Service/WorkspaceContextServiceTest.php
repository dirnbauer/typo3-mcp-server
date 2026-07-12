<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WorkspaceContextServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private WorkspaceContextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the workspace=0 rejection assertion meaningful by pinning local
        // mode off — otherwise DDEV / Development context would unlock live
        // writes and the test below stops being a security regression.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }

    public function testSwitchToOptimalWorkspaceCreatesWorkspaceIfNoneExist(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        self::assertGreaterThan(0, $workspaceId, 'Should create a workspace when none exist');
    }

    public function testSwitchToOptimalWorkspaceReturnsCurrentWorkspaceIfAlreadySet(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $first = $this->service->switchToOptimalWorkspace($backendUser);
        self::assertGreaterThan(0, $first);

        $second = $this->service->switchToOptimalWorkspace($backendUser);
        self::assertSame($first, $second, 'Should return current workspace when already set');
    }

    public function testSwitchToOptimalWorkspaceDoesNotTrustInaccessiblePreselectedWorkspace(): void
    {
        $this->createBackendUser(2);
        $this->createWorkspace(10, 'Permitted Workspace', '', 'be_users_2');
        $this->createWorkspace(20, 'Restricted Workspace', 'be_users_1', '');

        $backendUser = $this->setUpBackendUser(2);
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);

        // Model an untrusted preselection made by an optional transport adapter,
        // for example from an X-TYPO3-Workspace request header.
        $backendUser->workspace = 20;
        $backendUser->user['workspace_id'] = 20;
        $GLOBALS['BE_USER'] = $backendUser;

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        self::assertSame(10, $workspaceId);
        self::assertSame(10, $backendUser->workspace);
    }

    public function testSwitchToOptimalWorkspaceProvisionsIsolatedDraftForNonAdmin(): void
    {
        $this->createBackendUser(2);
        $this->createWorkspace(20, 'Restricted Workspace', 'be_users_1', '');

        $backendUser = $this->setUpBackendUser(2);
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $backendUser->workspace = 20;
        $backendUser->user['workspace_id'] = 20;
        $GLOBALS['BE_USER'] = $backendUser;

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        self::assertGreaterThan(0, $workspaceId);
        self::assertNotSame(20, $workspaceId);
        self::assertSame($workspaceId, $backendUser->workspace);

        $workspace = $backendUser->checkWorkspace($workspaceId);
        self::assertIsArray($workspace);
        self::assertSame('owner', $workspace['_ACCESS']);
        self::assertSame('be_users_2', $workspace['adminusers']);
    }

    public function testSwitchToOptimalWorkspaceFailsClosedWithoutAuthenticatedIdentity(): void
    {
        $backendUser = $GLOBALS['BE_USER'];
        $backendUser->user['uid'] = 0;
        $backendUser->user['admin'] = 0;
        $backendUser->groupData['workspace_perms'] = 0;

        $this->expectException(AccessDeniedException::class);

        $this->service->switchToOptimalWorkspace($backendUser);
    }

    public function testReadContextFallsBackToLiveWithoutRequiringWritePermission(): void
    {
        $this->createBackendUser(2);
        $this->createWorkspace(20, 'Restricted Workspace', 'be_users_1', '');
        $backendUser = $this->setUpBackendUser(2);
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        $backendUser->workspace = 20;
        $backendUser->user['workspace_id'] = 20;

        $workspaceId = $this->service->switchToReadWorkspace($backendUser);

        self::assertSame(0, $workspaceId);
        self::assertSame(0, $backendUser->workspace);
        self::assertSame(0, $backendUser->user['workspace_id']);
    }

    public function testExplicitInaccessibleReadWorkspaceIsRejected(): void
    {
        $this->createBackendUser(2);
        $this->createWorkspace(20, 'Restricted Workspace', 'be_users_1', '');
        $backendUser = $this->setUpBackendUser(2);
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);

        $this->expectException(AccessDeniedException::class);
        $this->service->switchToReadWorkspace($backendUser, 20);
    }

    public function testReadContextKeepsAccessibleDraftWithoutRequiringWritableAccess(): void
    {
        $this->createBackendUser(2);
        $this->createWorkspace(10, 'Readable Workspace', '', 'be_users_2');
        $backendUser = $this->setUpBackendUser(2);
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);

        $workspaceId = $this->service->switchToReadWorkspace($backendUser, 10);

        self::assertSame(10, $workspaceId);
        self::assertSame(10, $backendUser->workspace);
    }

    public function testSwitchToWorkspaceWithExistingWorkspace(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');

        $workspaceId = $this->service->switchToWorkspace($backendUser, 1);

        self::assertSame(1, $workspaceId);
    }

    public function testSwitchToWorkspaceRejectsZeroId(): void
    {
        $this->expectException(AccessDeniedException::class);

        $backendUser = $GLOBALS['BE_USER'];
        $this->service->switchToWorkspace($backendUser, 0);
    }

    public function testSwitchToOptimalWorkspaceDefaultsToLiveInLocalMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $backendUser = $GLOBALS['BE_USER'];
        $backendUser->workspace = 0;

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        self::assertSame(0, $workspaceId);
        self::assertSame(0, $backendUser->workspace);
    }

    public function testSwitchToOptimalWorkspaceKeepsDraftInLocalMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');

        $backendUser = $GLOBALS['BE_USER'];
        $this->service->switchToWorkspace($backendUser, 1);

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        self::assertSame(1, $workspaceId);
    }

    public function testGetWorkspaceInfoReturnsArray(): void
    {
        $info = $this->service->getWorkspaceInfo();

        self::assertIsArray($info);
        self::assertArrayHasKey('id', $info);
        self::assertArrayHasKey('title', $info);
    }

    public function testGetAvailableWorkspacesReturnsArray(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $workspaces = $this->service->getAvailableWorkspaces($backendUser);

        self::assertIsArray($workspaces);
    }

    public function testGetCurrentWorkspaceReturnsInt(): void
    {
        $wsId = $this->service->getCurrentWorkspace();

        self::assertIsInt($wsId);
    }

    private function createBackendUser(int $uid): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->insert('be_users', [
                'uid' => $uid,
                'pid' => 0,
                'username' => 'workspace_user_' . $uid,
                'password' => '',
                'admin' => 0,
                'disable' => 0,
                'deleted' => 0,
                'workspace_id' => 0,
                'workspace_perms' => 0,
                'userMods' => '',
                'tstamp' => time(),
                'crdate' => time(),
            ]);
    }

    private function createWorkspace(
        int $uid,
        string $title,
        string $adminUsers,
        string $members,
    ): void {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', [
                'uid' => $uid,
                'pid' => 0,
                'title' => $title,
                'adminusers' => $adminUsers,
                'members' => $members,
                'deleted' => 0,
            ]);
    }
}
