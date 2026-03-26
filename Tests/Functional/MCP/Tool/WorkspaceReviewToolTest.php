<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WorkspaceReviewTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class WorkspaceReviewToolTest extends AbstractFunctionalTest
{
    private WorkspaceReviewTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(WorkspaceReviewTool::class);
    }

    public function testEmptyReviewReturnsNoChanges(): void
    {
        // When no workspace_id is provided, AbstractRecordTool auto-selects one
        // The review should show zero changes for a fresh workspace
        $wsId = $this->createAndSwitchToWorkspace('Fresh Workspace');
        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(0, $data['totalChanges']);
    }

    public function testEmptyWorkspace(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Empty Test Workspace');

        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals($wsId, $data['workspaceId']);
        self::assertEquals(0, $data['totalChanges']);
        self::assertEmpty($data['changes']);
    }

    public function testWorkspaceWithModifiedRecord(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Review Test Workspace');

        // Modify a record via WriteTable to create a workspace version
        $writeTool = $this->getService(WriteTableTool::class);
        $writeResult = $writeTool->execute([
            'table' => 'pages',
            'action' => 'update',
            'uid' => 1,
            'data' => ['title' => 'Modified Title in Workspace'],
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        // Now review
        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['totalChanges']);
    }

    public function testFilterByTable(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Filter Test Workspace');

        $result = $this->tool->execute([
            'workspace_id' => $wsId,
            'table' => 'pages',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        // Only 'pages' should appear in changes (if any)
        foreach (array_keys($data['changes'] ?? []) as $table) {
            self::assertEquals('pages', $table);
        }
    }

    public function testResultStructure(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Structure Test Workspace');

        $result = $this->tool->execute(['workspace_id' => $wsId]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('workspaceId', $data);
        self::assertArrayHasKey('workspaceTitle', $data);
        self::assertArrayHasKey('changes', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('totalChanges', $data);
        self::assertArrayHasKey('returned', $data);
        self::assertArrayHasKey('limit', $data);
        self::assertArrayHasKey('offset', $data);
        self::assertArrayHasKey('hasMore', $data);
    }
}
