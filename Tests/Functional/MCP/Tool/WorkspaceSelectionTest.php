<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
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

        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'workspace_id' => $wsId,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    #[Test]
    public function invalidWorkspaceIdReturnsError(): void
    {
        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'workspace_id' => 99999,
        ]);

        self::assertTrue($result->isError, 'Expected error for invalid workspace ID');
    }

    #[Test]
    public function liveWorkspaceIdZeroReturnsError(): void
    {
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
            'data' => ['title' => 'Updated'],
            'workspace_id' => 0,
        ]);

        self::assertTrue($result->isError, 'Expected error for live workspace selection');
    }

    #[Test]
    public function omittedWorkspaceIdUsesDefault(): void
    {
        $this->createAndSwitchToWorkspace('Default WS');
        $this->switchToWorkspace(0);

        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => $this->getRootPageUid(),
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}
