<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaToolTest extends FunctionalTestCase
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
     * Test getting basic table schema information
     */
    public function testGetBasicTableSchema(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for tt_content table
        $result = $tool->execute([
            'table' => 'tt_content',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify essential schema information is present
        self::assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        self::assertStringContainsString('CURRENT RECORD TYPE:', $content);
        self::assertStringContainsString('FIELDS:', $content);
        self::assertStringContainsString('header', $content);
        self::assertStringContainsString('CType', $content);
    }

    /**
     * Test getting schema for a specific content type
     */
    public function testGetSchemaForSpecificType(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for textmedia content type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
        ]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Verify type-specific information
        self::assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        self::assertStringContainsString('Type: textmedia (Text & Media)', $content);
        self::assertStringContainsString('header', $content);
        self::assertStringContainsString('bodytext', $content);
    }

    /**
     * Test getting schema for pages table
     */
    public function testGetPagesTableSchema(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for pages table
        $result = $tool->execute([
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Verify pages table information
        self::assertStringContainsString('TABLE SCHEMA: pages', $content);
        self::assertStringContainsString('title', $content);
        self::assertStringContainsString('slug', $content);
        self::assertStringContainsString('doktype', $content);
    }

    public function testGetPagesTableSchemaIncludesCustomDoktype(): void
    {
        $this->registerCustomDoktype(137);

        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        self::assertStringContainsString('137 (Custom doktype 137)', $content);
        self::assertMatchesRegularExpression('/doktype.*\[Options:.*137 \(Custom doktype 137\)/s', $content);
    }

    public function testGetPagesTableSchemaForCustomDoktypeUsesDefaultLayout(): void
    {
        $this->registerCustomDoktype(137);

        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'type' => '137',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        self::assertStringContainsString('Type: 137 (Custom doktype 137)', $content);
        self::assertStringContainsString('FIELDS:', $content);
    }

    /**
     * Test getting schema for sys_category table
     * NOTE: This test expects an error because sys_category doesn't have a type field
     * and there's a bug in the tool that doesn't handle this gracefully
     */
    public function testGetSysCategoryTableSchema(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for sys_category table
        $result = $tool->execute([
            'table' => 'sys_category',
        ]);

        // sys_category doesn't have a type field, but should work now
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Verify basic schema information is present
        self::assertStringContainsString('TABLE SCHEMA: sys_category', $content);
        self::assertStringContainsString('title', $content);
        self::assertStringContainsString('parent', $content);
    }

    /**
     * Test error handling for missing table parameter
     */
    public function testMissingTableParameter(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Table parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for empty table parameter
     */
    public function testEmptyTableParameter(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => '',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Table parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for invalid table
     */
    public function testInvalidTable(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'nonexistent_table',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Cannot access table \'nonexistent_table\'', $result->content[0]->text);
    }

    /**
     * Test error handling for table without TCA
     */
    public function testTableWithoutTca(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'sys_log',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Cannot access table \'sys_log\'', $result->content[0]->text);
    }

    /**
     * Test getting schema with invalid type parameter
     */
    public function testInvalidTypeParameter(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for tt_content with invalid type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'nonexistent_type',
        ]);

        // The tool handles invalid types gracefully by returning an error message as content
        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Should show an error about the invalid type
        self::assertStringContainsString('ERROR:', $content);
        self::assertStringContainsString('nonexistent_type', $content);
        self::assertStringContainsString('does not exist', $content);
    }

    /**
     * Test schema output format and structure
     */
    public function testSchemaOutputFormat(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
        ]);

        self::assertFalse($result->isError);

        $content = $result->content[0]->text;

        // Verify schema structure contains expected sections
        self::assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        self::assertStringContainsString('=======================================', $content);
        self::assertStringContainsString('CONTROL FIELDS:', $content);
        self::assertStringContainsString('CURRENT RECORD TYPE:', $content);
        self::assertStringContainsString('FIELDS:', $content);

        // Verify field information is present
        self::assertStringContainsString('Type:', $content);
        self::assertStringContainsString('header', $content);
        self::assertStringContainsString('CType', $content);
    }

    /**
     * Test workspace context is properly initialized
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Should work in workspace context
        $result = $tool->execute([
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError);
        self::assertCount(1, $result->content);
    }

    /**
     * Test that richtext fields are properly marked
     */
    public function testRichtextFieldsAreMarked(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for textmedia type which has richtext bodytext
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Verify bodytext field shows richtext indicator
        self::assertMatchesRegularExpression('/bodytext.*\[Richtext\/HTML\]/', $content);

        // Verify typolink support is indicated
        self::assertStringContainsString('[Supports typolinks', $content);
        self::assertStringContainsString('t3://page?uid=123', $content);
        self::assertStringContainsString('t3://record?identifier=table&uid=456', $content);
    }

    /**
     * Tabs whose fields are all filtered out — or that have no fields at all
     * in the TCA showitem (e.g. sys_file_metadata's "Extended" tab) — must not
     * be rendered as orphan headers.
     */
    public function testEmptyTabsAreNotRendered(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'sys_file_metadata',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // The "Extended" tab in sys_file_metadata's TCA contains no fields,
        // so it should not appear in the output at all.
        $this->assertStringNotContainsString('(Extended):', $content);

        // No tab header should be left without at least one field/palette below it.
        $lines = explode("\n", $content);
        $tabHeader = '/^  \([^)]+\):$/';
        $childLine = '/^    [\s\S]/';
        foreach ($lines as $index => $line) {
            if (!preg_match($tabHeader, $line)) {
                continue;
            }
            $next = $lines[$index + 1] ?? '';
            $this->assertMatchesRegularExpression(
                $childLine,
                $next,
                "Tab header '{$line}' has no field below it (next line: '{$next}')"
            );
        }
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

    /**
     * Register a custom page doktype via TCA (TYPO3 v14-compatible replacement for
     * PageDoktypeRegistry->add(), which is deprecated in v14 and removed in v15).
     */
    private function registerCustomDoktype(int $doktype): void
    {
        $GLOBALS['TCA']['pages']['columns']['doktype']['config']['items'][] = [
            'label' => 'Custom doktype ' . $doktype,
            'value' => $doktype,
        ];
        $GLOBALS['TCA']['pages']['types'][(string)$doktype]['allowedRecordTypes'] = '*';
    }
}
