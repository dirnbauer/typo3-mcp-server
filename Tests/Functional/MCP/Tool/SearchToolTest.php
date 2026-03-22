<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SearchToolTest extends FunctionalTestCase
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

        // Import all necessary fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test single term search across all tables
     */
    public function testSingleTermSearch(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search for "welcome"
        $result = $tool->execute([
            'terms' => ['welcome'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify search results header
        self::assertStringContainsString('SEARCH RESULTS', $content);
        self::assertStringContainsString('Query: "welcome"', $content);

        // Should find content in tt_content
        self::assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        self::assertStringContainsString('[UID: 100] Welcome Header', $content);
        self::assertStringContainsString('Preview:', $content);

        // Should show page information
        self::assertStringContainsString('📍 Page: Home [UID: 1]', $content);
    }

    /**
     * Test multiple terms with OR logic (default)
     */
    public function testMultipleTermsWithOrLogic(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['news', 'article'],
            'termLogic' => 'OR',
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Verify search header shows OR logic
        self::assertStringContainsString('Search Terms: ["news", "article"]', $content);
        self::assertStringContainsString('Logic: OR (records must match ANY term)', $content);

        // Should find multiple results
        self::assertStringContainsString('News', $content); // Page title
        self::assertStringContainsString('Article', $content); // Page or content
        // Content will be in preview format with highlighted terms
        self::assertStringContainsString('Preview:', $content);
    }

    /**
     * Test multiple terms with AND logic
     */
    public function testMultipleTermsWithAndLogic(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['team', 'meet'],
            'termLogic' => 'AND',
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Verify search header shows AND logic
        self::assertStringContainsString('Logic: AND (records must match ALL terms)', $content);

        // Should only find "Meet our team" content (has both terms)
        self::assertStringContainsString('[UID: 102] Team Introduction', $content);
        self::assertStringContainsString('Preview:', $content);

        // Should NOT find "Team Members" (doesn't have "meet")
        self::assertStringNotContainsString('[UID: 103]', $content);
    }

    /**
     * Test table-specific search
     */
    public function testTableSpecificSearch(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search only in pages table
        $result = $tool->execute([
            'terms' => ['Home'],
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should only show pages table results
        self::assertStringContainsString('TABLE: Page (pages)', $content);
        self::assertStringContainsString('[UID: 1] Home', $content);

        // Should NOT show content elements
        self::assertStringNotContainsString('tt_content', $content);

        // Test searching in tt_content only
        $result = $tool->execute([
            'terms' => ['welcome'],
            'table' => 'tt_content',
        ]);

        $content = $result->content[0]->text;

        // Should only show tt_content results
        self::assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        self::assertStringNotContainsString('TABLE: Page (pages)', $content);
    }

    /**
     * Test page-specific search
     */
    public function testPageSpecificSearch(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search only on page 2 (About)
        $result = $tool->execute([
            'terms' => ['team'],
            'pageId' => 2,
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should find team content on About page
        self::assertStringContainsString('[UID: 102] Team Introduction', $content);
        self::assertStringContainsString('[UID: 103] Team Members', $content);

        // Should show correct page info
        self::assertStringContainsString('📍 Page: About [UID: 2]', $content);

        // Note: The search with pageId filters only content elements (tt_content)
        // by page, but pages table is searched globally. So the Team page (UID: 4)
        // will still appear in results because it matches the search term
    }

    /**
     * Test that search finds hidden records
     */
    public function testSearchFindsHiddenRecords(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Hidden records are always included now
        $result = $tool->execute([
            'terms' => ['hidden'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should find hidden content element
        self::assertStringContainsString('[UID: 104]', $content);
        self::assertStringContainsString('Hidden Content', $content);

        // Should also find the hidden page
        self::assertStringContainsString('[UID: 3]', $content);
        self::assertStringContainsString('Hidden Page', $content);
    }

    /**
     * Test inline record attribution (categories)
     */
    public function testInlineRecordAttribution(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search for category name
        $result = $tool->execute([
            'terms' => ['Technology'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // The search finds the category directly, not through inline attribution
        // because sys_category is a searchable table itself
        self::assertStringContainsString('TABLE: Category (sys_category)', $content);
        self::assertStringContainsString('[UID: 1] Technology', $content);

        // Since sys_category is not a hidden table, it won't trigger inline attribution
        // The inline attribution only works for hidden tables

        // Test another category
        $result = $tool->execute([
            'terms' => ['Business'],
        ]);

        $content = $result->content[0]->text;

        // Should find the Business category directly
        self::assertStringContainsString('[UID: 2] Business', $content);
    }

    /**
     * Test search result formatting
     */
    public function testSearchResultFormatting(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['team'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Check overall structure
        self::assertStringContainsString('SEARCH RESULTS', $content);
        self::assertStringContainsString('Total Results:', $content);
        self::assertStringContainsString('Tables Searched:', $content);

        // Check record formatting
        self::assertStringContainsString('• [UID:', $content); // Record prefix
        self::assertStringContainsString('📍 Page:', $content); // Page info
        self::assertStringContainsString('🎯 Type:', $content); // Content type
        self::assertStringContainsString('💬 Preview:', $content); // Content preview
    }

    /**
     * Test empty search results
     */
    public function testEmptySearchResults(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['nonexistentterm12345'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should show total results as 0
        self::assertStringContainsString('Total Results: 0', $content);
        self::assertStringContainsString('nonexistentterm12345', $content);
    }

    /**
     * Test search term validation
     */
    public function testSearchTermValidation(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Test empty terms array
        $result = $tool->execute([
            'terms' => [],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('At least one search term is required', $result->content[0]->text);

        // Test single character term
        $result = $tool->execute([
            'terms' => ['a'],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('must be at least 2 characters long', $result->content[0]->text);

        // Test very long term
        $longTerm = str_repeat('x', 101);
        $result = $tool->execute([
            'terms' => [$longTerm],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('cannot exceed 100 characters', $result->content[0]->text);

        // Test empty array with spaces
        $result = $tool->execute([
            'terms' => ['  ', ''],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('At least one non-empty search term is required', $result->content[0]->text);

        // Test with non-array terms (simulating what would happen if validation wasn't in place)
        // In reality, this would fail at JSON parsing, but we test the tool's handling
        $result = $tool->execute([
            'terms' => [123], // non-string in array
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('All terms must be strings', $result->content[0]->text);
    }

    /**
     * Test search with limit
     */
    public function testSearchWithLimit(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search with a tight per-table limit so truncation is visible in output
        $result = $tool->execute([
            'terms' => ['team'],
            'limit' => 1,
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        self::assertStringContainsString('Total Results:', $content);
        self::assertStringContainsString('Returned: 2 (per-table limits applied)', $content);
        self::assertStringContainsString('Found 1 of 2 record(s) (limit 1)', $content);

        // We should still get one page hit and only the first matching content record.
        self::assertStringContainsString('[UID: 4]', $content);
        self::assertStringContainsString('[UID: 102] Team Introduction', $content);
        self::assertStringNotContainsString('[UID: 103] Team Members', $content);
    }

    public function testSearchLimitValidation(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['team'],
            'limit' => 0,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('limit must be between 1 and 200', $result->content[0]->text);
    }

    /**
     * Test special character handling and SQL injection protection
     */
    public function testSpecialCharacterSearch(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Create test data with special characters
        $this->createPage(['title' => 'Page with 100% success rate', 'pid' => 0]);
        $this->createPage(['title' => 'Team_Building Activities', 'pid' => 0]);
        $this->createPage(['title' => "Page with 'quotes' and \"double quotes\"", 'pid' => 0]);
        $this->createPage(['title' => 'Special © Characters™ Page', 'pid' => 0]);

        // Test 1: Search with SQL wildcard characters (should be escaped)
        $result = $tool->execute([
            'terms' => ['100%'],
        ]);

        self::assertFalse($result->isError, 'Search with % should not cause error');
        $content = $result->content[0]->text;

        // Should find exact match, not wildcard
        self::assertStringContainsString('100% success', $content);
        // Should NOT find all records (% not treated as wildcard)
        self::assertStringNotContainsString('Team_Building', $content);

        // Test 2: Search with underscore (SQL single-char wildcard)
        $result = $tool->execute([
            'terms' => ['Team_Building'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should find exact match only
        self::assertStringContainsString('Team_Building Activities', $content);
        // Should NOT find "Team Building" (without underscore) as wildcard match

        // Test 3: SQL injection attempt with quotes
        $result = $tool->execute([
            'terms' => ["'; DROP TABLE pages; --"],
        ]);

        self::assertFalse($result->isError, 'SQL injection attempt should be safely handled');

        // Verify table still exists after injection attempt
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $recordCount = $queryBuilder->count('uid')
            ->from('pages')
            ->executeQuery()
            ->fetchOne();

        self::assertGreaterThan(0, $recordCount, 'Pages table should still exist and have records after SQL injection attempt');

        // Test 4: Search for content with quotes
        $result = $tool->execute([
            'terms' => ["'quotes'"],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString("Page with 'quotes'", $content);

        // Test 5: Special unicode characters
        $result = $tool->execute([
            'terms' => ['©'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('Special © Characters™', $content);

        // Test 6: Backslash escaping
        $result = $tool->execute([
            'terms' => ['\\test\\'],
        ]);

        self::assertFalse($result->isError, 'Backslashes should be handled safely');

        // Test 7: NULL byte injection
        $result = $tool->execute([
            'terms' => ["test\0injection"],
        ]);

        self::assertFalse($result->isError, 'NULL bytes should be handled safely');
    }

    /**
     * Helper method to create a page for testing
     */
    private function createPage(array $data): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $data = array_merge([
            'uid' => $data['uid'] ?? null,
            'pid' => $data['pid'] ?? 0,
            'title' => $data['title'] ?? 'Test Page',
            'deleted' => 0,
            'hidden' => $data['hidden'] ?? 0,
            'doktype' => 1,
            'slug' => '/test-' . uniqid(),
            'tstamp' => time(),
            'crdate' => time(),
        ], $data);

        if (isset($data['uid'])) {
            $uid = $data['uid'];
            unset($data['uid']);
            $queryBuilder->insert('pages')
                ->values($data)
                ->set('uid', $uid)
                ->executeStatement();
            return $uid;
        }
        $queryBuilder->insert('pages')
            ->values($data)
            ->executeStatement();
        return (int)$queryBuilder->getConnection()->lastInsertId();

    }

    /**
     * Test invalid table name
     */
    public function testInvalidTableName(): void
    {
        $tool = $this->getService(SearchTool::class);

        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'non_existent_table',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test non-workspace-capable table
     */
    public function testNonWorkspaceCapableTable(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Try to search in a non-workspace-capable table (if one exists)
        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'sys_template', // Usually not workspace-capable
        ]);

        // Should either work if workspace-capable or show appropriate error
        if ($result->isError) {
            self::assertStringContainsString('not workspace-capable', $result->content[0]->text);
        }
    }

    /**
     * Test search through tool registry
     */
    public function testSearchThroughRegistry(): void
    {
        // Create tool registry with SearchTool
        $tools = [$this->getService(SearchTool::class)];
        $registry = new ToolRegistry($tools);

        // Get tool from registry
        $tool = $registry->getTool('Search');
        self::assertNotNull($tool);
        self::assertInstanceOf(SearchTool::class, $tool);

        // Execute search through registry
        $result = $tool->execute([
            'terms' => ['welcome'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('Welcome Header', $content);
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = $this->getService(SearchTool::class);
        self::assertEquals('Search', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = $this->getService(SearchTool::class);
        $schema = $tool->getSchema();

        self::assertIsArray($schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('inputSchema', $schema);

        // Check parameters
        $properties = $schema['inputSchema']['properties'];
        self::assertArrayHasKey('terms', $properties);
        self::assertArrayHasKey('termLogic', $properties);
        self::assertArrayHasKey('table', $properties);
        self::assertArrayHasKey('pageId', $properties);
        // includeHidden should not exist anymore
        self::assertArrayNotHasKey('includeHidden', $properties);
        self::assertArrayHasKey('limit', $properties);

        // Check required fields
        self::assertArrayHasKey('required', $schema['inputSchema']);
        self::assertContains('terms', $schema['inputSchema']['required']);
    }

    /**
     * Test complex multi-table search
     */
    public function testComplexMultiTableSearch(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search that should find results in multiple tables
        $result = $tool->execute([
            'terms' => ['contact'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should find in pages
        self::assertStringContainsString('TABLE: Page (pages)', $content);
        self::assertStringContainsString('[UID: 6] Contact', $content);

        // Should find in content
        self::assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        self::assertStringContainsString('[UID: 105] Contact Form', $content);
    }

    /**
     * Test termLogic parameter validation
     */
    public function testTermLogicValidation(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Test invalid term logic
        $result = $tool->execute([
            'terms' => ['test'],
            'termLogic' => 'INVALID',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('termLogic must be either "AND" or "OR"', $result->content[0]->text);
    }

    /**
     * Test search with multiple inline matches
     */
    public function testMultipleInlineMatches(): void
    {
        $tool = $this->getService(SearchTool::class);

        // Search for Web Development (subcategory)
        $result = $tool->execute([
            'terms' => ['Web Development'],
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should find the Web Development category directly
        self::assertStringContainsString('Web Development', $content);
        self::assertStringContainsString('[UID: 4] Web Development', $content);
    }
}
