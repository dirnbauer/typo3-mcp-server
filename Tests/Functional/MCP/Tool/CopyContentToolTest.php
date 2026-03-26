<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\CopyContentTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class CopyContentToolTest extends AbstractFunctionalTest
{
    private CopyContentTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(CopyContentTool::class);
    }

    public function testCopyContentToSamePage(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Copy Test Workspace');

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'targetPid' => 1,
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('tt_content', $data['table']);
        self::assertEquals(100, $data['sourceUid']);
        self::assertGreaterThan(0, $data['newUid']);
        self::assertEquals(1, $data['targetPid']);
    }

    public function testCopyContentToDifferentPage(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Copy Test Workspace 2');

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'targetPid' => 2,
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(2, $data['targetPid']);
        self::assertGreaterThan(0, $data['newUid']);
        self::assertNotEquals(100, $data['newUid']);
    }

    public function testCopyWithOverrides(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Copy Override Workspace');

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'targetPid' => 1,
            'overrides' => ['header' => 'Copied Content Header'],
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['newUid']);
        self::assertArrayHasKey('overridesApplied', $data);
        self::assertEquals('Copied Content Header', $data['overridesApplied']['header']);
    }

    public function testCopyInvalidTableReturnsError(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Copy Error Workspace');

        $result = $this->tool->execute([
            'table' => 'nonexistent_table',
            'uid' => 100,
            'targetPid' => 1,
            'workspace_id' => $wsId,
        ]);
        self::assertTrue($result->isError, 'Expected error for nonexistent table');
    }

    public function testCopyMissingUidReturnsError(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 0,
            'targetPid' => 1,
        ]);
        self::assertTrue($result->isError, 'Expected error for uid=0');
    }

    public function testResultStructure(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Structure Test');

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'targetPid' => 1,
            'workspace_id' => $wsId,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('table', $data);
        self::assertArrayHasKey('sourceUid', $data);
        self::assertArrayHasKey('newUid', $data);
        self::assertArrayHasKey('targetPid', $data);
    }
}
