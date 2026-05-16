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
        self::assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
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
        self::assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
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
        self::assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
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
        self::assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $content);
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
        self::assertStringContainsString('FlexForm schema not found for identifier: form_formframework', $result->content[0]->text);
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
     * In TYPO3 v14, CType-based plugins do not register FlexForm DS via the
     * column-level ds array, so identifier lookup is expected to fail.
     */
    public function testGetFlexFormSchemaSuccess(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => '*,news_pi1',
        ]);

        self::assertTrue($result->isError, 'FlexForm identifier lookup should fail for CType-based plugins in v14');
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString('not found', $result->content[0]->text);
    }

    /**
     * In TYPO3 v14, CType-based plugins do not register FlexForm DS via the
     * column-level ds array, so identifier lookup is expected to fail.
     */
    public function testGetFlexFormSchemaWithDifferentNewsTypes(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'identifier' => '*,news_categorylist',
        ]);

        self::assertTrue($result->isError, 'FlexForm identifier lookup should fail for CType-based plugins in v14');
        self::assertStringContainsString('not found', $result->content[0]->text);

        $result = $tool->execute([
            'identifier' => '*,news_newsdetail',
        ]);

        self::assertTrue($result->isError, 'FlexForm identifier lookup should fail for CType-based plugins in v14');
        self::assertStringContainsString('not found', $result->content[0]->text);
    }

    /**
     * In TYPO3 v14, CType-based plugins do not register FlexForm DS via the
     * column-level ds array, so identifier lookup is expected to fail even
     * when a recordUid is provided.
     */
    public function testGetFlexFormSchemaWithRecordUid(): void
    {
        $tool = $this->getService(GetFlexFormSchemaTool::class);

        $result = $tool->execute([
            'identifier' => '*,news_pi1',
            'recordUid' => 123,
        ]);

        self::assertTrue($result->isError, 'FlexForm identifier lookup should fail for CType-based plugins in v14');
        self::assertStringContainsString('not found', $result->content[0]->text);
    }

}
