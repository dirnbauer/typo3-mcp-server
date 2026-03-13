<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\ListWorkspacesTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ListWorkspacesToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function listWorkspacesShowsAvailableWorkspaces(): void
    {
        $this->createAndSwitchToWorkspace('WS Alpha');
        $this->switchToWorkspace(0);

        $tool = GeneralUtility::makeInstance(ListWorkspacesTool::class);
        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('WS Alpha', $text);
    }

    #[Test]
    public function listWorkspacesShowsEmptyWhenNoneExist(): void
    {
        $tool = GeneralUtility::makeInstance(ListWorkspacesTool::class);
        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('No workspaces available', $text);
    }
}
