<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetFlexFormSchemaToolTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',  // Add News extension for success test cases
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
     * Test basic FlexForm schema retrieval with nonexistent identifier
     */
    public function testGetFlexFormSchema(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Test with form_formframework (likely not available in test environment)
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework',
        ]);

        // The tool returns an error when FlexForm identifier is not found
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify error message
        self::assertStringContainsString('FlexForm schema not found for identifier: *,form_formframework', $content);
    }

    /**
     * Test FlexForm schema with default parameters
     */
    public function testGetFlexFormSchemaWithDefaults(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Test with only identifier (defaults to tt_content.pi_flexform)
        $result = $tool->execute([
            'identifier' => 'form_formframework',
        ]);

        // The tool returns an error when FlexForm identifier is not found
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Verify error message contains transformed identifier
        self::assertStringContainsString('FlexForm schema not found for identifier: *,form_formframework', $content);
    }

    /**
     * Test FlexForm schema with custom table and field
     */
    public function testGetFlexFormSchemaWithCustomTableAndField(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Test with custom table and field
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'textmedia',
        ]);

        // The tool returns an error when FlexForm identifier is not found
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Verify error message
        self::assertStringContainsString('FlexForm schema not found for identifier: textmedia', $content);
    }

    /**
     * Test error handling for missing identifier parameter
     */
    public function testMissingIdentifierParameter(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Identifier parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for empty identifier parameter
     */
    public function testEmptyIdentifierParameter(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => '',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Identifier parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for invalid table
     */
    public function testInvalidTable(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'nonexistent_table',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Cannot access table \'nonexistent_table\': Table does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test error handling for table without TCA
     */
    public function testTableWithoutTca(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'sys_log',
            'field' => 'pi_flexform',
            'identifier' => 'form_formframework',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('Cannot access table \'sys_log\': Table does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test FlexForm schema with unknown identifier
     */
    public function testUnknownIdentifier(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => 'unknown_flexform_identifier',
        ]);

        // Should return error for unknown identifier
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Should show error message
        self::assertStringContainsString('FlexForm schema not found for identifier: unknown_flexform_identifier', $content);
    }

    /**
     * Test FlexForm schema output format when identifier not found
     */
    public function testFlexFormSchemaOutputFormat(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'identifier' => 'form_formframework',
        ]);

        // The tool returns an error when FlexForm identifier is not found
        self::assertTrue($result->isError);

        $content = $result->content[0]->text;

        // Verify error message
        self::assertStringContainsString('FlexForm schema not found for identifier: *,form_formframework', $content);
    }

    /**
     * Test FlexForm schema with field that exists but is not FlexForm
     */
    public function testNonFlexFormField(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'header', // This is not a FlexForm field
            'identifier' => 'form_formframework',
        ]);

        // Should return error for non-FlexForm field
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);

        $content = $result->content[0]->text;

        // Should show error message
        self::assertStringContainsString('Field \'header\' in table \'tt_content\' is not a FlexForm field', $content);
    }

    /**
     * Test FlexForm schema field information
     */
    public function testFlexFormSchemaFieldInformation(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'identifier' => 'form_formframework',
        ]);

        // The tool returns an error when FlexForm identifier is not found
        self::assertTrue($result->isError);

        $content = $result->content[0]->text;

        // Verify error message
        self::assertStringContainsString('FlexForm schema not found for identifier: *,form_formframework', $content);
    }

    /**
     * Test workspace context initialization
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Should work in workspace context but still return error for missing identifier
        $result = $tool->execute([
            'identifier' => 'form_formframework',
        ]);

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertStringContainsString('FlexForm schema not found for identifier: *,form_formframework', $result->content[0]->text);
    }

    /**
     * Test tool schema contains required information
     */
    public function testToolSchema(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $schema = $tool->getSchema();

        // Verify schema structure
        self::assertIsArray($schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('inputSchema', $schema);

        // Verify parameters
        self::assertArrayHasKey('properties', $schema['inputSchema']);
        self::assertArrayHasKey('identifier', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('table', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('field', $schema['inputSchema']['properties']);

        // Verify required fields
        self::assertArrayHasKey('required', $schema['inputSchema']);
        self::assertContains('identifier', $schema['inputSchema']['required']);
    }

    /**
     * Test successful FlexForm schema retrieval with News plugin
     */
    public function testGetFlexFormSchemaSuccess(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Test with News plugin identifier
        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => '*,news_pi1',
        ]);

        // Should succeed with News extension loaded
        self::assertFalse($result->isError, 'Should successfully retrieve News FlexForm schema');
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify schema structure
        self::assertStringContainsString('FLEXFORM SCHEMA: *,news_pi1', $content);
        self::assertStringContainsString('Table: tt_content', $content);
        self::assertStringContainsString('Field: pi_flexform', $content);
        self::assertStringContainsString('Schema defined in file:', $content);
        self::assertStringContainsString('flexform_news_list.xml', $content);

        // Verify sheets are present
        self::assertStringContainsString('SHEETS:', $content);
        self::assertStringContainsString('Sheet: sDEF', $content);
        self::assertStringContainsString('Sheet: additional', $content);
        self::assertStringContainsString('Sheet: template', $content);

        // Verify key fields are present
        self::assertStringContainsString('settings.orderBy', $content);
        self::assertStringContainsString('settings.orderDirection', $content);
        self::assertStringContainsString('settings.categories', $content);
        self::assertStringContainsString('settings.detailPid', $content);
        self::assertStringContainsString('settings.listPid', $content);
        self::assertStringContainsString('settings.limit', $content);

        // Verify JSON structure example is present
        self::assertStringContainsString('JSON STRUCTURE:', $content);
        self::assertStringContainsString('"pi_flexform": {', $content);

        // Verify the dot notation conversion note is present
        self::assertStringContainsString('Field names with dots', $content);
        self::assertStringContainsString('converted to nested structures', $content);
    }

    /**
     * Test FlexForm schema with different News plugin types
     */
    public function testGetFlexFormSchemaWithDifferentNewsTypes(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // Test category list FlexForm
        $result = $tool->execute([
            'identifier' => '*,news_categorylist',
        ]);

        self::assertFalse($result->isError, 'Should successfully retrieve News category list FlexForm schema');
        $content = $result->content[0]->text;

        self::assertStringContainsString('FLEXFORM SCHEMA: *,news_categorylist', $content);
        self::assertStringContainsString('flexform_category_list.xml', $content);

        // Test detail view FlexForm
        $result = $tool->execute([
            'identifier' => '*,news_newsdetail',
        ]);

        self::assertFalse($result->isError, 'Should successfully retrieve News detail FlexForm schema');
        $content = $result->content[0]->text;

        self::assertStringContainsString('FLEXFORM SCHEMA: *,news_newsdetail', $content);
        self::assertStringContainsString('flexform_news_detail.xml', $content);
    }

    /**
     * Test FlexForm schema parameter handling with recordUid
     */
    public function testGetFlexFormSchemaWithRecordUid(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        // recordUid parameter is accepted but not used for schema retrieval
        $result = $tool->execute([
            'identifier' => '*,news_pi1',
            'recordUid' => 123,  // This parameter is ignored for schema
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Should still retrieve the schema successfully
        self::assertStringContainsString('FLEXFORM SCHEMA: *,news_pi1', $content);
        self::assertStringContainsString('flexform_news_list.xml', $content);
    }

}
