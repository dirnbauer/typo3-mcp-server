<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\BulkWriteTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class BulkWriteToolTest extends AbstractFunctionalTest
{
    private BulkWriteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(BulkWriteTool::class);
    }

    public function testCreateMultipleRecords(): void
    {
        $result = $this->tool->execute([
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'Bulk Record 1', 'CType' => 'text'],
                ],
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'Bulk Record 2', 'CType' => 'text'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(2, $data['totalOperations']);
        self::assertEquals(2, $data['successCount']);

        // Both should have new UIDs
        foreach ($data['results'] as $opResult) {
            self::assertTrue($opResult['success']);
            self::assertArrayHasKey('newUid', $opResult);
            self::assertGreaterThan(0, $opResult['newUid']);
        }
    }

    public function testMixedOperations(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Bulk Mixed Test');

        // First create a record to update/delete later
        $createResult = $this->tool->execute([
            'workspace_id' => $wsId,
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'To Be Updated', 'CType' => 'text'],
                ],
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));

        $createData = json_decode($this->getFirstTextContent($createResult), true);
        self::assertIsArray($createData);
        $createdUid = $createData['results'][0]['newUid'] ?? 0;
        self::assertGreaterThan(0, $createdUid);

        // Now do mixed operations: create + update
        $result = $this->tool->execute([
            'workspace_id' => $wsId,
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'Brand New', 'CType' => 'text'],
                ],
                [
                    'action' => 'update',
                    'table' => 'tt_content',
                    'uid' => $createdUid,
                    'data' => ['header' => 'Updated Header'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(2, $data['totalOperations']);
    }

    public function testValidationRejectsMissingFields(): void
    {
        $result = $this->tool->execute([
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    // Missing pid and data
                ],
            ],
        ]);
        self::assertTrue($result->isError);
    }

    public function testValidationRejectsEmptyOperations(): void
    {
        $result = $this->tool->execute([
            'operations' => [],
        ]);
        self::assertTrue($result->isError);
    }

    public function testValidationRejectsInvalidAction(): void
    {
        $result = $this->tool->execute([
            'operations' => [
                [
                    'action' => 'invalid_action',
                    'table' => 'tt_content',
                ],
            ],
        ]);
        self::assertTrue($result->isError);
    }

    public function testReturnsPerOperationResults(): void
    {
        $result = $this->tool->execute([
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'Result Check', 'CType' => 'text'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('results', $data);
        self::assertCount(1, $data['results']);

        $opResult = $data['results'][0];
        self::assertEquals(0, $opResult['index']);
        self::assertEquals('create', $opResult['action']);
        self::assertEquals('tt_content', $opResult['table']);
        self::assertTrue($opResult['success']);
        self::assertArrayHasKey('newUid', $opResult);
    }

    public function testDeleteOperation(): void
    {
        $wsId = $this->createAndSwitchToWorkspace('Bulk Delete Test');

        // Create then delete
        $createResult = $this->tool->execute([
            'workspace_id' => $wsId,
            'operations' => [
                [
                    'action' => 'create',
                    'table' => 'tt_content',
                    'pid' => 1,
                    'data' => ['header' => 'To Be Deleted', 'CType' => 'text'],
                ],
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));

        $createData = json_decode($this->getFirstTextContent($createResult), true);
        self::assertIsArray($createData);
        $createdUid = $createData['results'][0]['newUid'] ?? 0;
        self::assertGreaterThan(0, $createdUid);

        $deleteResult = $this->tool->execute([
            'workspace_id' => $wsId,
            'operations' => [
                [
                    'action' => 'delete',
                    'table' => 'tt_content',
                    'uid' => $createdUid,
                ],
            ],
        ]);
        $this->assertFalse($deleteResult->isError, json_encode($deleteResult->jsonSerialize()));

        $deleteData = json_decode($this->getFirstTextContent($deleteResult), true);
        self::assertIsArray($deleteData);
        self::assertEquals(1, $deleteData['successCount']);
    }
}
