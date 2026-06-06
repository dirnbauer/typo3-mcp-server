<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class WorkspaceSelectionTest extends AbstractFunctionalTest
{
    #[Test]
    public function explicitWorkspaceIdIsUsed(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Explicit WS');
        $this->switchToWorkspace(0);

        $tool = $this->getService(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'workspace_id' => $wsId,
        ]);

        $this->assertSuccessfulToolResult($result);
    }

    #[Test]
    public function invalidWorkspaceIdReturnsError(): void
    {
        $tool = $this->getService(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'workspace_id' => 99999,
        ]);

        $this->assertToolError($result);
    }

    #[Test]
    public function liveWorkspaceIdZeroReturnsError(): void
    {
        $tool = $this->getService(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'data' => ['title' => 'Updated'],
            'workspace_id' => 0,
        ]);

        $this->assertToolError($result);
    }

    #[Test]
    public function omittedWorkspaceIdUsesDefault(): void
    {
        $this->createAndSwitchToWorkspace('Default WS');
        $this->switchToWorkspace(0);

        $tool = $this->getService(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
        ]);

        $this->assertSuccessfulToolResult($result);
    }

    #[Test]
    public function omittedWorkspaceIdDefaultsToLiveWhenLocalModeEnabledViaUserTsconfig(): void
    {
        $this->setUserTsConfig('options.mcpServer.localUnsafeMode = on');
        $this->switchToWorkspace(0);

        $tool = $this->getService(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'data' => ['title' => 'Live via local mode default'],
        ]);

        $this->assertSuccessfulToolResult($result);

        $workspaceInfo = GeneralUtility::makeInstance(WorkspaceContextService::class)->getWorkspaceInfo();
        self::assertTrue($workspaceInfo['is_live'], json_encode($workspaceInfo));
    }

    #[Test]
    public function omittedWorkspaceIdUsesDraftWhenUserTsconfigDisablesLocalMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $this->setUserTsConfig('options.mcpServer.localUnsafeMode = off');
        $this->switchToWorkspace(0);

        $tool = $this->getService(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'data' => ['title' => 'Draft via TSconfig opt-out'],
        ]);

        $this->assertSuccessfulToolResult($result);

        $workspaceInfo = GeneralUtility::makeInstance(WorkspaceContextService::class)->getWorkspaceInfo();
        self::assertFalse($workspaceInfo['is_live'], json_encode($workspaceInfo));
    }

    #[Test]
    public function liveWorkspaceIdZeroSucceedsWhenLocalModeEnabledViaUserTsconfig(): void
    {
        $this->setUserTsConfig('options.mcpServer.localUnsafeMode = on');

        $tool = $this->getService(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'data' => ['title' => 'Live explicit'],
            'workspace_id' => 0,
        ]);

        $this->assertSuccessfulToolResult($result);
    }
}
