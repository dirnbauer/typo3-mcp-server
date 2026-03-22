<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Trait providing common MCP-specific assertions
 *
 * Reduces duplication of assertion code across test classes
 */
trait McpAssertionsTrait
{
    protected function getFirstTextContent(CallToolResult $result): string
    {
        $this->assertNotEmpty($result->content, 'Expected MCP tool result to contain at least one content item');
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        return $result->content[0]->text;
    }

    /**
     * Assert that a tool result is successful (not an error)
     *
     * @param CallToolResult $result The result to check
     * @param string $message Optional message for failure
     */
    protected function assertSuccessfulToolResult(CallToolResult $result, string $message = ''): void
    {
        $this->assertInstanceOf(CallToolResult::class, $result, $message);
        $this->assertFalse(
            $result->isError,
            ($message ?: 'Tool returned error') . ': ' . json_encode($result->jsonSerialize()),
        );
    }

    /**
     * Assert that a tool result is an error
     *
     * @param CallToolResult $result The result to check
     * @param string|null $expectedMessage Optional expected error message
     */
    protected function assertToolError(CallToolResult $result, ?string $expectedMessage = null): string
    {
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError, 'Expected error but tool succeeded: ' . json_encode($result->jsonSerialize()));

        $errorMessage = $this->getFirstTextContent($result);

        if ($expectedMessage !== null) {
            $this->assertStringContainsString(
                $expectedMessage,
                $errorMessage,
                'Error message does not contain expected text',
            );
        }

        return $errorMessage;
    }

    /**
     * Assert that a result contains a workspace ID
     *
     * @param CallToolResult $result
     */
    protected function assertHasWorkspace(CallToolResult $result): void
    {
        $data = $this->extractJsonFromResult($result);
        $workspaceId = $data['workspaceId'] ?? $data['workspace_id'] ?? null;

        $this->assertNotNull($workspaceId, 'Workspace ID is missing from MCP result JSON');
        $this->assertGreaterThan(0, (int)$workspaceId, 'Workspace ID should be greater than 0');
    }

    /**
     * Assert that a record contains expected field values
     *
     * @param array $expected Expected field values
     * @param array $actual Actual record data
     * @param array|null $fields Fields to check (null = all fields in expected)
     */
    protected function assertRecordEquals(array $expected, array $actual, ?array $fields = null): void
    {
        $fields ??= array_keys($expected);

        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $actual, "Field '$field' missing in actual record");
            $this->assertEquals(
                $expected[$field],
                $actual[$field],
                "Field '$field' does not match expected value",
            );
        }
    }

    /**
     * Assert that a result contains valid record data
     *
     * @param CallToolResult $result
     * @param string $key The key containing the record (default: 'record')
     */
    protected function assertHasValidRecord(CallToolResult $result, string $key = 'record'): void
    {
        $data = $this->extractJsonFromResult($result);
        $this->assertArrayHasKey($key, $data);
        $this->assertIsArray($data[$key]);
        $this->assertArrayHasKey('uid', $data[$key]);
        $this->assertGreaterThan(0, $data[$key]['uid']);
    }

    /**
     * Assert that a result contains a list of records
     *
     * @param CallToolResult $result
     * @param string $key The key containing records (default: 'records')
     * @param int|null $expectedCount Expected number of records (null = any)
     */
    protected function assertHasRecordList(CallToolResult $result, string $key = 'records', ?int $expectedCount = null): void
    {
        $data = $this->extractJsonFromResult($result);
        $this->assertArrayHasKey($key, $data);
        $this->assertIsArray($data[$key]);

        if ($expectedCount !== null) {
            $this->assertCount($expectedCount, $data[$key]);
        }
    }

    /**
     * Assert pagination data in result
     *
     * @param CallToolResult $result
     * @param int $expectedLimit
     * @param int $expectedOffset
     */
    protected function assertHasPagination(CallToolResult $result, int $expectedLimit, int $expectedOffset): void
    {
        $data = $this->extractJsonFromResult($result);

        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('offset', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertArrayHasKey('nextOffset', $data);
        $this->assertArrayHasKey('hasMore', $data);

        $this->assertEquals($expectedLimit, $data['limit']);
        $this->assertEquals($expectedOffset, $data['offset']);
        $this->assertIsBool($data['hasMore']);
        $this->assertIsInt($data['total']);
        $this->assertIsInt($data['count']);
        $this->assertSame(\count($data['records'] ?? []), $data['count']);

        if ($data['hasMore']) {
            $this->assertIsInt($data['nextOffset']);
            $this->assertGreaterThan($data['offset'], $data['nextOffset']);
        } else {
            $this->assertNull($data['nextOffset']);
        }
    }

    /**
     * Assert that essential fields are present in a record
     *
     * @param array $record
     * @param array $additionalFields Additional fields to check beyond essentials
     */
    protected function assertHasEssentialFields(array $record, array $additionalFields = []): void
    {
        $essentialFields = ['uid', 'pid', 'tstamp', 'crdate'];
        $allFields = array_merge($essentialFields, $additionalFields);

        foreach ($allFields as $field) {
            $this->assertArrayHasKey($field, $record, "Essential field '$field' missing");
        }
    }

    /**
     * Assert date field format (ISO 8601)
     *
     * @param string|null $dateValue
     * @param string $fieldName
     */
    protected function assertDateFormat($dateValue, string $fieldName): void
    {
        if ($dateValue === null || $dateValue === '') {
            return;
        }

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string)$dateValue,
            "Field '$fieldName' is not in ISO 8601 format",
        );
    }

    /**
     * Extract JSON data from MCP result
     *
     * @param CallToolResult $result
     * @return array
     */
    protected function extractJsonFromResult(CallToolResult $result): array
    {
        $this->assertSuccessfulToolResult($result);

        $data = json_decode($this->getFirstTextContent($result), true);
        $this->assertIsArray($data, 'Expected JSON payload in MCP tool result text content');

        return $data;
    }

    /**
     * Assert that a record exists in workspace but not in live
     *
     * @param string $table
     * @param int $uid
     */
    protected function assertRecordInWorkspace(string $table, int $uid): void
    {
        $connection = GeneralUtility::makeInstance(
            ConnectionPool::class,
        )->getConnectionForTable($table);

        // Check record doesn't exist in live (workspace 0)
        $liveCount = $connection->count('uid', $table, [
            'uid' => $uid,
            't3ver_wsid' => 0,
        ]);

        $this->assertEquals(0, $liveCount, "Record $table:$uid should not exist in live workspace");

        // Check record exists in current workspace
        $workspaceId = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($workspaceId > 0) {
            $workspaceCount = $connection->count('uid', $table, [
                't3ver_oid' => $uid,
                't3ver_wsid' => $workspaceId,
            ]);

            $this->assertGreaterThan(0, $workspaceCount, "Record $table:$uid should exist in workspace $workspaceId");
        }
    }
}
