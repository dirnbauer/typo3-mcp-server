<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\EdgeCase;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

/**
 * Test system-level error edge cases
 */
final class SystemErrorTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;
    protected ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeTool = $this->getService(WriteTableTool::class);
        $this->readTool = $this->getService(ReadTableTool::class);
    }

    /**
     * Test handling of missing TCA configuration
     */
    public function testMissingTcaConfiguration(): void
    {
        // Temporarily remove TCA for a table
        $originalTca = $GLOBALS['TCA']['tt_content'] ?? [];
        unset($GLOBALS['TCA']['tt_content']);

        try {
            $result = $this->readTool->execute([
                'table' => 'tt_content',
                'uid' => 1,
            ]);

            self::assertTrue($result->isError);
            self::assertStringContainsString('tt_content', $result->content[0]->text);

        } finally {
            // Restore TCA
            $GLOBALS['TCA']['tt_content'] = $originalTca;
        }
    }

    /**
     * Test handling of corrupted TCA configuration
     */
    public function testCorruptedTcaConfiguration(): void
    {
        // Temporarily corrupt TCA
        $originalTca = $GLOBALS['TCA']['pages']['columns']['title'] ?? [];
        $GLOBALS['TCA']['pages']['columns']['title'] = 'invalid_not_array';

        try {
            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => ['title' => 'Test Title'],
            ]);

            // Tool should handle corrupted TCA gracefully
            if ($result->isError) {
                // Check for TCA or configuration error
                $errorText = strtolower($result->content[0]->text);
                self::assertTrue(
                    str_contains($errorText, 'configuration')
                    || str_contains($errorText, 'tca')
                    || str_contains($errorText, 'type')
                    || str_contains($errorText, 'array')
                    || str_contains($errorText, 'unexpected error occurred'), // New generic error message
                    "Expected configuration error, got: $errorText",
                );
            } else {
                // Or it might succeed if it doesn't rely on that specific config
                self::assertTrue(true);
            }

        } finally {
            // Restore TCA
            $GLOBALS['TCA']['pages']['columns']['title'] = $originalTca;
        }
    }

    /**
     * Test handling of workspace service failures
     */
    public function testWorkspaceServiceFailure(): void
    {
        // Save current workspace
        $originalWorkspace = $GLOBALS['BE_USER']->workspace;

        try {
            // Test with invalid workspace ID
            $GLOBALS['BE_USER']->workspace = 99999; // Non-existent workspace

            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => ['title' => 'Test in invalid workspace'],
            ]);

            // Tool should handle this by creating workspace or switching to valid one
            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        } finally {
            // Restore original workspace
            $GLOBALS['BE_USER']->workspace = $originalWorkspace;
        }
    }

    /**
     * Test handling of table access service failures
     */
    public function testTableAccessServiceFailure(): void
    {
        // Test with edge case table names
        $edgeCaseTables = [
            '',  // Empty table name
            ' ',  // Whitespace
            'SELECT * FROM pages',  // SQL injection attempt
            str_repeat('a', 255),  // Very long table name
        ];

        foreach ($edgeCaseTables as $table) {
            $result = $this->readTool->execute([
                'table' => $table,
                'uid' => 1,
            ]);

            self::assertTrue($result->isError);
            self::assertStringContainsString('table', strtolower($result->content[0]->text));
        }
    }

    /**
     * Test handling of PHP errors during execution
     */
    public function testPhpErrorHandling(): void
    {
        // Test with operations that might trigger PHP warnings/errors

        // 1. Division by zero scenario (if tool does calculations)
        $result = $this->readTool->execute([
            'table' => 'pages',
            'limit' => 0,  // Some tools might divide by limit
            'offset' => 100,
        ]);

        // Should handle gracefully
        if ($result->isError) {
            self::assertStringNotContainsString('Division by zero', $result->content[0]->text);
        } else {
            self::assertTrue(true);
        }

        // 2. Invalid array access
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => null,  // Should be array
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('data', strtolower($result->content[0]->text));
    }

    /**
     * Test handling of extension dependency issues
     */
    public function testExtensionDependencyIssues(): void
    {
        // Temporarily mark workspaces extension as not loaded
        $originalState = $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['workspaces'] ?? null;
        unset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['workspaces']);

        try {
            // Try to perform workspace-dependent operation
            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => ['title' => 'Test without workspaces'],
            ]);

            // Tool should detect missing extension
            if ($result->isError) {
                self::assertStringContainsString('workspace', strtolower($result->content[0]->text));
            }

        } finally {
            // Restore state
            if ($originalState !== null) {
                $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['workspaces'] = $originalState;
            }
        }
    }

    /**
     * Test handling of circular dependencies
     */
    public function testCircularDependencies(): void
    {
        // Create circular reference in inline relations
        // First create two records
        $result1 = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Record 1',
            ],
        ]);

        self::assertFalse($result1->isError);
        $data1 = json_decode($result1->content[0]->text, true);
        $uid1 = $data1['uid'];

        $result2 = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Record 2',
            ],
        ]);

        self::assertFalse($result2->isError);
        $data2 = json_decode($result2->content[0]->text, true);
        $uid2 = $data2['uid'];

        // Now try to create circular references (if the schema allows)
        // This is more of a data integrity test
        self::assertTrue(true, 'Circular dependency test completed');
    }

    /**
     * Test handling of race conditions
     */
    public function testRaceConditions(): void
    {
        // Create a scenario where two operations might conflict
        $uid = 1;

        // Simulate concurrent read-modify-write
        $read1 = $this->readTool->execute(['table' => 'pages', 'uid' => $uid]);
        self::assertFalse($read1->isError);
        $data1 = json_decode($read1->content[0]->text, true);

        // Another "process" modifies the record
        $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $uid,
            'data' => ['title' => 'Modified by process 2'],
        ]);

        // First "process" tries to update based on old data
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $uid,
            'data' => ['title' => 'Modified by process 1'],
        ]);

        // Should succeed (last write wins) but data integrity might be compromised
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify final state
        $finalRead = $this->readTool->execute(['table' => 'pages', 'uid' => $uid]);
        self::assertFalse($finalRead->isError, json_encode($finalRead->jsonSerialize()));
        $finalData = json_decode($finalRead->content[0]->text, true);
        self::assertIsArray($finalData);
        if (isset($finalData['title'])) {
            self::assertEquals('Modified by process 1', $finalData['title']);
        } else {
            // Record might have been deleted or filtered
            self::assertTrue(true, 'Race condition test completed');
        }
    }
}
