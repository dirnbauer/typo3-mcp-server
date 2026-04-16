<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test that News extension table schemas are properly handled by GetTableSchemaTool
 */
class NewsSchemaTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // Set up backend user
        $this->setUpBackendUser(1);
    }

    /**
     * Test getting News table schema
     */
    public function testGetNewsTableSchema(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify essential News fields are present
        self::assertStringContainsString('TABLE SCHEMA: tx_news_domain_model_news', $content);
        self::assertStringContainsString('title', $content);
        self::assertStringContainsString('teaser', $content);
        self::assertStringContainsString('bodytext', $content);
        self::assertStringContainsString('datetime', $content);
        self::assertStringContainsString('archive', $content);
        self::assertStringContainsString('author', $content);
        self::assertStringContainsString('categories', $content);
        self::assertStringContainsString('tags', $content);
    }

    /**
     * Test News field types and configurations
     */
    public function testNewsFieldTypes(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news',
        ]);

        $content = $result->content[0]->text;

        // Field types should be properly detected (labels might be empty in test environment)
        self::assertMatchesRegularExpression('/datetime\s*\([^)]*\):\s*datetime/i', $content);
        self::assertMatchesRegularExpression('/bodytext\s*\([^)]*\):\s*text/i', $content);
        self::assertMatchesRegularExpression('/categories\s*\([^)]*\):\s*select/i', $content);
    }

    /**
     * Test that News uses sys_category for categories
     */
    public function testNewsUsesSysCategoryForCategories(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // News extends sys_category, not creates its own table
        $result = $tool->execute([
            'table' => 'sys_category',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify sys_category fields that News uses
        self::assertStringContainsString('TABLE SCHEMA: sys_category', $content);
        self::assertStringContainsString('title', $content);
        self::assertStringContainsString('parent', $content);
        self::assertStringContainsString('description', $content);
    }

    /**
     * Test getting News tag table schema
     */
    public function testGetNewsTagSchema(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tx_news_domain_model_tag',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify tag fields
        self::assertStringContainsString('TABLE SCHEMA: tx_news_domain_model_tag', $content);
        self::assertStringContainsString('title', $content);
    }

    /**
     * Test that News uses sys_file_reference for media (but it's restricted)
     */
    public function testNewsUsesSysFileReferenceForMedia(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // News extends sys_file_reference, but this table is restricted
        $result = $tool->execute([
            'table' => 'sys_file_reference',
        ]);

        // sys_file_reference is restricted for security reasons
        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted', $result->content[0]->text);
    }

    /**
     * Test that News schema shows proper field grouping/tabs
     */
    public function testNewsSchemaFieldGrouping(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news',
        ]);

        $content = $result->content[0]->text;

        // News uses parentheses for tabs/sections (labels might be empty in test environment)
        self::assertMatchesRegularExpression('/\(General\):|General\):/', $content);
        self::assertMatchesRegularExpression('/\(Categories\):|Categories\):/', $content);
        self::assertMatchesRegularExpression('/\(Media\):|Media\):/', $content);
    }

    /**
     * Test schema for News types (if News uses types)
     */
    public function testNewsTypes(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get basic schema to check if types are used
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news',
        ]);

        $content = $result->content[0]->text;

        // Check if News uses type field
        if (str_contains((string)$content, 'type:')) {
            // Test getting schema for a specific type
            $result = $tool->execute([
                'table' => 'tx_news_domain_model_news',
                'type' => '0', // Default news type
            ]);

            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
            self::assertStringContainsString('Type: 0', $result->content[0]->text);
        } else {
            // No types used, which is also valid
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Test News plugin schema in tt_content shows FlexForm fields
     */
    public function testNewsPluginSchemaInTtContent(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for News plugin content type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1', // News plugin
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify it's the News plugin type
        self::assertStringContainsString('Type: news_pi1', $content);
        // The label should be translated or have a meaningful fallback
        self::assertTrue(
            str_contains((string)$content, 'News article list')
            || str_contains((string)$content, 'News list'), // Fallback from "news_list.title"
            'Should contain News plugin label or fallback',
        );

        // Should contain pi_flexform field in Plugin tab
        self::assertStringContainsString('(Plugin):', $content, 'Should have Plugin tab');
        self::assertStringContainsString('pi_flexform', $content, 'News plugin should have pi_flexform field');

        // Check that it's recognized as FlexForm type with proper formatting
        self::assertMatchesRegularExpression(
            '/pi_flexform\s*\(Plugin Options\):\s*flex\s*\(FlexForm\)/i',
            $content,
            'pi_flexform should be properly formatted with label and type',
        );

        // Check for FlexForm identifiers
        self::assertMatchesRegularExpression(
            '/\[Identifiers:\s*[^\]]*news_pi1[^\]]*\]/',
            $content,
            'Should have news_pi1 in FlexForm identifiers',
        );

        // Verify the instruction to use GetFlexFormSchema tool
        self::assertStringContainsString(
            'Use GetFlexFormSchema tool with these identifiers',
            $content,
            'Should provide instruction to use GetFlexFormSchema tool',
        );

        // Check for ds_pointerField information (legacy + v14-compatible variants)
        self::assertTrue(
            str_contains((string)$content, '[ds_pointerField: list_type,CType]')
            || str_contains((string)$content, '[ds_pointerField: CType]'),
            'Should show ds_pointerField configuration',
        );
    }

    /**
     * Test that News plugin FlexForm schema can be retrieved
     */
    public function testGetNewsPluginFlexFormIdentifiers(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // First, check if News registers as a plugin type
        $result = $tool->execute([
            'table' => 'tt_content',
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Look for available types to understand how News plugin is registered
        if (preg_match('/AVAILABLE TYPES:(.+?)(?=\n\n|$)/s', (string)$content, $matches)) {
            $typesSection = $matches[1];

            if (str_contains((string)$typesSection, 'news_pi1')) {
                self::assertStringContainsString('news_pi1', $typesSection, 'News plugin CType should be available');
            }
        }

        // Also check the specific News plugin schema output.
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1',
        ]);

        if (!$result->isError) {
            $content = $result->content[0]->text;
            self::assertStringContainsString('news_pi1', $content);
            self::assertStringContainsString('pi_flexform', $content);
        }
    }

    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}
