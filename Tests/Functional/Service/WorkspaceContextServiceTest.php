<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

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

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }

    public function testSwitchToOptimalWorkspaceCreatesWorkspaceIfNoneExist(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $workspaceId = $this->service->switchToOptimalWorkspace($backendUser);

        $this->assertGreaterThan(0, $workspaceId, 'Should create a workspace when none exist');
    }

    public function testSwitchToOptimalWorkspaceReturnsCurrentWorkspaceIfAlreadySet(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $first = $this->service->switchToOptimalWorkspace($backendUser);
        $this->assertGreaterThan(0, $first);

        $second = $this->service->switchToOptimalWorkspace($backendUser);
        $this->assertSame($first, $second, 'Should return current workspace when already set');
    }

    public function testSwitchToWorkspaceWithExistingWorkspace(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');

        $workspaceId = $this->service->switchToWorkspace($backendUser, 1);

        $this->assertSame(1, $workspaceId);
    }

    public function testSwitchToWorkspaceRejectsZeroId(): void
    {
        $this->expectException(\Hn\McpServer\Exception\AccessDeniedException::class);

        $backendUser = $GLOBALS['BE_USER'];
        $this->service->switchToWorkspace($backendUser, 0);
    }

    public function testGetWorkspaceInfoReturnsArray(): void
    {
        $info = $this->service->getWorkspaceInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('id', $info);
        $this->assertArrayHasKey('title', $info);
    }

    public function testGetAvailableWorkspacesReturnsArray(): void
    {
        $backendUser = $GLOBALS['BE_USER'];

        $workspaces = $this->service->getAvailableWorkspaces($backendUser);

        $this->assertIsArray($workspaces);
    }

    public function testGetCurrentWorkspaceReturnsInt(): void
    {
        $wsId = $this->service->getCurrentWorkspace();

        $this->assertIsInt($wsId);
    }
}
