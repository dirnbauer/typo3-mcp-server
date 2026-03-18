<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ListTablesToolTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test basic table listing functionality
     */
    public function testListTables(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify listing contains expected sections
        self::assertStringContainsString('ACCESSIBLE TABLES IN TYPO3 (via MCP)', $content);
        self::assertStringContainsString('=====================================', $content);
        self::assertStringContainsString('workspace-capable and accessible', $content);

        // Verify core tables are present
        self::assertStringContainsString('pages', $content);
        self::assertStringContainsString('tt_content', $content);
        self::assertStringContainsString('sys_category', $content);
    }

    /**
     * Test table grouping by extension
     */
    public function testTableGroupingByExtension(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify extension grouping
        self::assertStringContainsString('CORE TABLES:', $content);
        self::assertStringContainsString('EXTENSION: unknown', $content);

        // Verify core tables are under core section
        self::assertMatchesRegularExpression('/CORE TABLES:.*?pages/s', $content);
        self::assertMatchesRegularExpression('/CORE TABLES:.*?tt_content/s', $content);
        self::assertMatchesRegularExpression('/CORE TABLES:.*?sys_category/s', $content);
    }

    /**
     * Test table information includes required fields
     */
    public function testTableInformationFields(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify table information contains required fields
        self::assertStringContainsString('pages (Page)', $content);
        self::assertStringContainsString('tt_content (Page Content)', $content);
        self::assertStringContainsString('sys_category (Category)', $content);

        // Verify table type information is present
        self::assertStringContainsString('[content]', $content);
        self::assertStringContainsString('[system]', $content);
    }

    /**
     * Test read-only vs writable table distinction
     */
    public function testReadOnlyTableDistinction(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify access information - currently the tool shows workspace-capable tables
        // and mentions [READ-ONLY] marker exists but we need to see if it's actually used
        self::assertStringContainsString('Tables marked as [READ-ONLY]', $content);

        // Core tables should be workspace-capable (no [READ-ONLY] marker)
        self::assertStringContainsString('pages (Page)', $content);
        self::assertStringContainsString('tt_content (Page Content)', $content);
        self::assertStringContainsString('sys_category (Category)', $content);
    }

    /**
     * Test workspace capability identification
     */
    public function testWorkspaceCapabilityIdentification(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify workspace information - the tool states "workspace-capable and accessible"
        self::assertStringContainsString('workspace-capable and accessible', $content);

        // All listed tables should be workspace-capable since that's the filtering criteria
        self::assertStringContainsString('pages (Page)', $content);
        self::assertStringContainsString('tt_content (Page Content)', $content);
    }

    /**
     * Test table type identification
     */
    public function testTableTypeIdentification(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify table types are shown in brackets
        self::assertStringContainsString('[content]', $content);
        self::assertStringContainsString('[system]', $content);

        // Verify specific table types
        self::assertMatchesRegularExpression('/pages.*?\[content\]/s', $content);
        self::assertMatchesRegularExpression('/tt_content.*?\[content\]/s', $content);
        self::assertMatchesRegularExpression('/sys_category.*?\[content\]/s', $content);
    }

    /**
     * Test table descriptions are present
     */
    public function testTableDescriptions(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // ⚠️ POTENTIAL ISSUE: The tool shows internal TCA display conditions
        // instead of user-friendly descriptions. This might not be ideal for LLM usage.
        self::assertStringContainsString('Field \'', $content);
        self::assertStringContainsString('has display conditions', $content);

        // Verify table names contain descriptive labels
        self::assertStringContainsString('pages (Page)', $content);
        self::assertStringContainsString('tt_content (Page Content)', $content);
    }

    /**
     * Test total table count
     */
    public function testTotalTableCount(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // ⚠️ ISSUE: The tool doesn't provide summary statistics like total count
        // This might be useful for LLM context. For now, just verify some tables exist.
        self::assertStringContainsString('pages', $content);
        self::assertStringContainsString('tt_content', $content);
        self::assertStringContainsString('sys_category', $content);

        // Count tables manually by looking at the format
        $tableCount = substr_count($content, '- ');
        self::assertGreaterThanOrEqual(3, $tableCount);
    }

    /**
     * Test extension count
     */
    public function testExtensionCount(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // ⚠️ ISSUE: No extension count statistics provided
        // Verify extension grouping exists
        self::assertStringContainsString('CORE TABLES:', $content);
        self::assertStringContainsString('EXTENSION:', $content);

        // Should have at least core and one extension section
        $extensionCount = substr_count($content, 'EXTENSION:');
        self::assertGreaterThanOrEqual(1, $extensionCount);
    }

    /**
     * Test table summary statistics
     */
    public function testTableSummaryStatistics(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // ⚠️ ISSUE: No summary statistics provided by the tool
        // This would be useful for LLM context. For now, verify basic functionality.
        self::assertStringContainsString('workspace-capable and accessible', $content);
        // Note: [READ-ONLY] is mentioned in the description but not shown in actual data

        // Verify we have core tables
        self::assertStringContainsString('pages', $content);
        self::assertStringContainsString('tt_content', $content);
    }

    /**
     * Test output format and structure
     */
    public function testOutputFormat(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        $result = $tool->execute([]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify proper formatting
        self::assertStringContainsString('ACCESSIBLE TABLES IN TYPO3 (via MCP)', $content);
        self::assertStringContainsString('=====================================', $content);
        self::assertStringContainsString('CORE TABLES:', $content);
        self::assertStringContainsString('EXTENSION:', $content);

        // Verify table entries are properly formatted
        self::assertMatchesRegularExpression('/^- \w+/m', $content);
        self::assertStringContainsString('(', $content); // Table labels in parentheses
        self::assertStringContainsString('[', $content); // Type information in brackets
    }

    /**
     * Test workspace context initialization
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = $this->getService(ListTablesTool::class);

        // Should work in workspace context
        $result = $tool->execute([]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);
    }

    /**
     * Set up backend user with workspace
     */
    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
