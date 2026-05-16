<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

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
}
