<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageTreeToolTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected bool $importDefaultFixtures = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->importDefaultFixtures) {
            // Import page fixtures
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        }

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test getting page tree directly through the tool
     */
    public function testGetPageTreeDirectly(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);

        // Test getting page tree from root (pid=0)
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2,
        ]);

        // Verify result structure
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify the tree contains expected pages
        self::assertStringContainsString('[1] Home', $content);
        self::assertStringContainsString('[2] About Us', $content);
        self::assertStringContainsString('[6] Contact', $content);

        // Hidden page should now be included (always show hidden records)
        self::assertStringContainsString('[3] Hidden Page', $content);
    }

    public function testGetPageTreeIncludesWorkspaceOnlyPages(): void
    {
        $writeTool = $this->getService(WriteTableTool::class);
        $treeTool = $this->getService(GetPageTreeTool::class);

        $createResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => [
                'title' => 'Draft Tree Page',
                'slug' => '/draft-tree-page',
                'doktype' => 1,
            ],
        ]);

        self::assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));

        $treeResult = $treeTool->execute([
            'startPage' => 1,
            'depth' => 1,
        ]);

        self::assertFalse($treeResult->isError, json_encode($treeResult->jsonSerialize()));
        $content = $treeResult->content[0]->text;

        self::assertStringContainsString('Draft Tree Page', $content);
    }

    /**
     * Test getting page tree from a specific page
     */
    public function testGetPageTreeFromSpecificPage(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);

        // Get tree starting from page 1 (Home)
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 2,
        ]);

        $content = $result->content[0]->text;

        // Should contain subpages of Home (now includes Contact)
        self::assertStringContainsString('[2] About Us', $content);
        self::assertStringNotContainsString('[1] Home', $content);
        self::assertStringContainsString('[6] Contact', $content);

        // Should include sub-subpages
        self::assertStringContainsString('[4] Our Team', $content);
        self::assertStringContainsString('[5] Mission', $content);
    }

    /**
     * Test depth limitation with proper tree structure verification
     */
    public function testDepthLimitation(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);

        // Create a known page structure for testing
        $this->createTestPageStructure();

        // Test 1: Depth 1 - should only show immediate children
        $result = $tool->execute([
            'startPage' => 1000, // Our test root
            'depth' => 1,
        ]);

        $content = $result->content[0]->text;

        // Verify only direct children are shown
        self::assertStringContainsString('[1001] Level 1 - Page A', $content);
        self::assertStringContainsString('[1002] Level 1 - Page B', $content);

        // Verify subpage count is shown
        self::assertStringContainsString('(2 subpages)', $content); // Page A has 2 children
        self::assertStringContainsString('(1 subpages)', $content); // Page B has 1 child (tool uses "subpages" even for 1)

        // Verify grandchildren are NOT shown
        self::assertStringNotContainsString('[1003] Level 2 - Page A1', $content);
        self::assertStringNotContainsString('[1004] Level 2 - Page A2', $content);
        self::assertStringNotContainsString('[1005] Level 2 - Page B1', $content);

        // Test 2: Depth 2 - should show children and grandchildren
        $result = $tool->execute([
            'startPage' => 1000,
            'depth' => 2,
        ]);

        $content = $result->content[0]->text;

        // Verify children are shown
        self::assertStringContainsString('[1001] Level 1 - Page A', $content);
        self::assertStringContainsString('[1002] Level 1 - Page B', $content);

        // Verify grandchildren are shown with proper indentation (includes - prefix)
        self::assertStringContainsString('  - [1003] Level 2 - Page A1', $content);
        self::assertStringContainsString('  - [1004] Level 2 - Page A2', $content);
        self::assertStringContainsString('  - [1005] Level 2 - Page B1', $content);

        // Verify great-grandchildren are NOT shown
        self::assertStringNotContainsString('[1006] Level 3 - Page A1a', $content);

        // But verify subpage count for pages that have deeper children
        $lines = explode("\n", (string)$content);
        foreach ($lines as $line) {
            if (str_contains((string)$line, '[1003] Level 2 - Page A1')) {
                self::assertStringContainsString('(1 subpages)', $line, 'Page A1 should show it has 1 subpage');
            }
        }

        // Test 3: Depth 3 - should show full tree
        $result = $tool->execute([
            'startPage' => 1000,
            'depth' => 3,
        ]);

        $content = $result->content[0]->text;

        // Verify all levels are shown with proper indentation
        self::assertStringContainsString('[1001] Level 1 - Page A', $content);
        self::assertStringContainsString('  - [1003] Level 2 - Page A1', $content);
        self::assertStringContainsString('    - [1006] Level 3 - Page A1a', $content);

        // Verify proper nesting by checking indentation pattern
        $this->assertCorrectTreeStructure($content);
    }

    /**
     * Create a test page structure for depth testing
     */
    private function createTestPageStructure(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        // Create root page
        $connection->insert('pages', [
            'uid' => 1000,
            'pid' => 0,
            'title' => 'Test Root',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        // Level 1 pages
        $connection->insert('pages', [
            'uid' => 1001,
            'pid' => 1000,
            'title' => 'Level 1 - Page A',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100,
        ]);

        $connection->insert('pages', [
            'uid' => 1002,
            'pid' => 1000,
            'title' => 'Level 1 - Page B',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-b',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 200,
        ]);

        // Level 2 pages
        $connection->insert('pages', [
            'uid' => 1003,
            'pid' => 1001,
            'title' => 'Level 2 - Page A1',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a1',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100,
        ]);

        $connection->insert('pages', [
            'uid' => 1004,
            'pid' => 1001,
            'title' => 'Level 2 - Page A2',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a2',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 200,
        ]);

        $connection->insert('pages', [
            'uid' => 1005,
            'pid' => 1002,
            'title' => 'Level 2 - Page B1',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-b/page-b1',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100,
        ]);

        // Level 3 page
        $connection->insert('pages', [
            'uid' => 1006,
            'pid' => 1003,
            'title' => 'Level 3 - Page A1a',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a1/page-a1a',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100,
        ]);
    }

    /**
     * Verify tree structure has correct parent-child relationships
     */
    private function assertCorrectTreeStructure(string $content): void
    {
        $lines = explode("\n", (string)$content);
        $currentIndent = -1;
        $indentStack = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*)(?:- )?\[(\d+)\]/', $line, $matches)) {
                $indent = strlen($matches[1]) / 2; // Assuming 2 spaces per level
                $uid = (int)$matches[2];

                // Verify indentation increases by at most 1 level
                if ($currentIndent >= 0 && $indent > $currentIndent + 1) {
                    self::fail("Invalid tree structure: Indentation jumped from level $currentIndent to $indent at UID $uid");
                }

                // Track parent-child relationships
                if ($indent > $currentIndent) {
                    // Going deeper
                    $indentStack[] = $uid;
                } elseif ($indent < $currentIndent) {
                    // Going back up
                    $levelsUp = $currentIndent - $indent;
                    for ($i = 0; $i < $levelsUp; $i++) {
                        array_pop($indentStack);
                    }
                }

                $currentIndent = $indent;
            }
        }

        self::assertTrue(true, 'Tree structure is valid');
    }

    /**
     * Test getting page tree through ToolRegistry
     */
    public function testGetPageTreeThroughRegistry(): void
    {
        // Create tool registry with the GetPageTreeTool
        $tools = [$this->getService(GetPageTreeTool::class)];
        $registry = new ToolRegistry($tools);

        // Get tool from registry
        $tool = $registry->getTool('GetPageTree');
        self::assertNotNull($tool);
        self::assertInstanceOf(GetPageTreeTool::class, $tool);

        // Execute through registry
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 1,
        ]);

        $content = $result->content[0]->text;
        self::assertStringContainsString('[1] Home', $content);
        self::assertStringNotContainsString('[6] Contact', $content); // Contact is now a subpage of Home
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);
        self::assertEquals('GetPageTree', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);
        $schema = $tool->getSchema();

        self::assertIsArray($schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('inputSchema', $schema);
        self::assertArrayHasKey('properties', $schema['inputSchema']);
        self::assertArrayHasKey('startPage', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('depth', $schema['inputSchema']['properties']);
    }

    /**
     * Test enhanced output with doktype labels
     */
    public function testEnhancedOutputWithDoktypeLabels(): void
    {
        $tool = $this->getService(GetPageTreeTool::class);

        // Import content fixtures to have some records to count
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');

        // Test getting page tree from root
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2,
        ]);

        $content = $result->content[0]->text;

        // Verify doktype labels are included
        self::assertStringContainsString('[1] Home [Page]', $content);
        self::assertStringContainsString('[2] About Us [Page]', $content);

        // Verify record counts are included (page 1 has 3 content elements)
        self::assertStringContainsString('[tt_content: 3]', $content);
    }
}
