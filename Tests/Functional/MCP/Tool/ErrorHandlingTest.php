<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test the new error handling implementation
 */
final class ErrorHandlingTest extends AbstractFunctionalTest
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    /**
     * Test ValidationException handling in SearchTool
     */
    #[Test]
    public function testSearchToolValidationException(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Test missing terms
        $result = $tool->execute([]);
        $this->assertToolError($result, 'Provide either "query" (string or array of strings) or "terms" (array of strings).');

        // Test empty terms array
        $result = $tool->execute(['terms' => []]);
        $this->assertToolError($result, 'At least one search term is required');

        // Test invalid term logic
        $result = $tool->execute([
            'terms' => ['test'],
            'termLogic' => 'INVALID',
        ]);
        $this->assertToolError($result, 'termLogic must be either "AND" or "OR"');

        // Test terms with invalid types
        $result = $tool->execute([
            'terms' => ['valid', 123, 'another'],
        ]);
        $this->assertToolError($result, 'All terms must be strings');

        // Test terms that are too short
        $result = $tool->execute([
            'terms' => ['a'],
        ]);
        $this->assertToolError($result, 'at least 2 characters long');

        // Test unknown language code
        $result = $tool->execute([
            'terms' => ['test'],
            'language' => 'unknown_lang',
        ]);
        $this->assertToolError($result, 'Unknown language code');
    }

    /**
     * Test ValidationException handling in ReadTableTool
     */
    #[Test]
    public function testReadTableToolValidationException(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Test missing table
        $result = $tool->execute([]);
        $this->assertToolError($result, 'Table name is required');

        // Test invalid limit
        $result = $tool->execute([
            'table' => 'pages',
            'limit' => 1001,
        ]);
        $this->assertToolError($result, 'Limit must be between 1 and 1000');

        // Test negative offset
        $result = $tool->execute([
            'table' => 'pages',
            'offset' => -1,
        ]);
        $this->assertToolError($result, 'Offset must be non-negative');

        // Test unknown language code
        $result = $tool->execute([
            'table' => 'pages',
            'language' => 'xyz',
        ]);
        $this->assertToolError($result, 'Unknown language code');
    }

    /**
     * Test table access validation using validateTableAccessWithError
     */
    #[Test]
    public function testTableAccessValidation(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Test non-existent table
        $result = $tool->execute([
            'table' => 'non_existent_table',
        ]);
        $this->assertToolError($result, 'Cannot access table');

        // Test restricted table (non-workspace capable)
        $result = $tool->execute([
            'table' => 'be_users',
        ]);
        $this->assertToolError($result, 'Cannot access table');
    }

    /**
     * Test error logging functionality
     */
    #[Test]
    public function testErrorLogging(): void
    {
        // This test would verify that errors are properly logged
        // For now, we'll just ensure the error handling doesn't break the execution

        $tool = $this->getService(SearchTool::class);

        // Force an invalid table search to trigger error handling
        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'invalid_table_name',
        ]);

        $this->assertToolError($result, 'Cannot search table');
    }

    /**
     * Test consistent error messages across tools
     */
    #[Test]
    public function testConsistentErrorMessages(): void
    {
        $readTool = $this->getService(ReadTableTool::class);
        $writeTool = $this->getService(WriteTableTool::class);

        // Test the same error in different tools
        $readResult = $readTool->execute(['table' => 'non_existent']);
        $writeResult = $writeTool->execute([
            'action' => 'update',
            'table' => 'non_existent',
            'uid' => 1,
            'data' => ['title' => 'test'],
        ]);

        $this->assertToolError($readResult, 'Cannot access table');
        $this->assertToolError($writeResult, 'Cannot access table');
    }

    /**
     * Test that database exceptions are properly caught and converted
     */
    #[Test]
    public function testDatabaseExceptionHandling(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Trigger a validation error via filters with an invalid operator
        $result = $tool->execute([
            'table' => 'pages',
            'filters' => [
                ['field' => 'uid', 'operator' => 'INVALID_OP', 'value' => '1'],
            ],
        ]);

        // Should handle the error gracefully
        $errorText = $this->assertToolError($result);
        self::assertStringContainsString('invalid operator', $errorText);
    }

    /**
     * Test that unexpected errors are handled with generic messages
     */
    #[Test]
    public function testUnexpectedErrorHandling(): void
    {
        // This is harder to test directly, but we can verify the error handling
        // doesn't expose internal details

        $tool = $this->getService(SearchTool::class);

        // Search with very complex terms that might trigger edge cases
        $result = $tool->execute([
            'terms' => [str_repeat('complex', 10)],
            'termLogic' => 'AND',
            'limit' => 1,
        ]);

        // Should either succeed or fail gracefully
        if ($result->isError) {
            // Error message should not contain stack traces or internal paths
            $errorText = $this->assertToolError($result);
            self::assertStringNotContainsString('Stack trace:', $errorText);
            self::assertStringNotContainsString('/var/www/', $errorText);
            self::assertStringNotContainsString('\\Hn\\McpServer\\', $errorText);
        }
    }

    /**
     * Test executeWithErrorHandling method functionality
     */
    #[Test]
    public function testExecuteWithErrorHandlingMethod(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Test with valid search that should succeed
        $result = $tool->execute([
            'terms' => ['test'],
            'limit' => 10,
        ]);

        // Even if no results, it shouldn't be an error
        $this->assertSuccessfulToolResult($result);

        // Test with parameters that trigger validation error
        $result = $tool->execute([
            'terms' => ['x'], // Too short
        ]);

        $this->assertToolError($result, 'at least 2 characters');
    }

    /**
     * Test that workspace operations handle errors correctly
     */
    #[Test]
    public function testWorkspaceErrorHandling(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Try to write to a non-workspace capable table
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'sys_log', // Not workspace capable
            'pid' => 0,
            'data' => ['details' => 'test'],
        ]);

        $this->assertToolError($result, 'Cannot access table');
    }
}
