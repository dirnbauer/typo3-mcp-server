<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\WorkspaceContextService;
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
}
