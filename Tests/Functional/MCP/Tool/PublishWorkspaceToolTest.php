<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\PublishWorkspaceTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class PublishWorkspaceToolTest extends AbstractFunctionalTest
{
    private PublishWorkspaceTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(PublishWorkspaceTool::class);
    }

    public function testDryRunReturnsPreview(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Publish Dry Run Test');

        // Create a record in the workspace
        $writeTool = $this->getService(WriteTableTool::class);
        $writeResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['header' => 'Dry Run Content', 'CType' => 'text'],
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        // Dry-run publish (default)
        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertTrue($data['dryRun']);
        self::assertGreaterThan(0, $data['totalRecords']);
        self::assertArrayHasKey('tables', $data);
    }

    public function testPublishMakesChangesLive(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Publish Execute Test');

        // Modify a page title in workspace
        $writeTool = $this->getService(WriteTableTool::class);
        $writeResult = $writeTool->execute([
            'table' => 'pages',
            'action' => 'update',
            'uid' => 1,
            'data' => ['title' => 'Published Title'],
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        // Execute publish
        $result = $this->tool->execute([
            'workspace_id' => $wsId,
            'dryRun' => false,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertFalse($data['dryRun']);
        self::assertTrue($data['published']);
        self::assertGreaterThan(0, $data['totalRecords']);
    }

    public function testPublishRequiresNonLiveWorkspace(): void
    {
        // Switch to live (workspace 0)
        $this->switchToWorkspace(0);

        $result = $this->tool->execute(['workspace_id' => 0]);
        self::assertTrue($result->isError);
    }

    public function testEmptyWorkspaceReturnsNoChanges(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Empty Publish Test');

        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(0, $data['totalRecords']);
    }

    public function testTableFilterOnlyPublishesFilteredTable(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Table Filter Publish Test');

        $writeTool = $this->getService(WriteTableTool::class);

        // Create content in workspace
        $writeResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['header' => 'Filter Content', 'CType' => 'text'],
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        // Dry-run with table filter
        $result = $this->tool->execute([
            'workspace_id' => $wsId,
            'table' => 'tt_content',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        // Only tt_content should appear
        foreach (array_keys($data['tables'] ?? []) as $table) {
            self::assertEquals('tt_content', $table);
        }
    }
}
