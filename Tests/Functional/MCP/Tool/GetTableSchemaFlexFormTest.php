<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test GetTableSchemaTool FlexForm discoverability
 */
class GetTableSchemaFlexFormTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test that GetTableSchemaTool shows pi_flexform for the News plugin CType
     */
    public function testListTypeShowsPiFlexForm(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        // Get schema for the News plugin CType
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Check if pi_flexform appears
        $hasFlexForm = str_contains((string)$content, 'pi_flexform');
        self::assertTrue($hasFlexForm, 'Schema for list type should include pi_flexform field');

        self::assertStringContainsString('news_pi1', $content);

        // Check if it mentions GetFlexFormSchema tool
        $mentionsFlexFormTool = str_contains((string)$content, 'GetFlexFormSchema');
        self::assertTrue($mentionsFlexFormTool, 'Schema should mention GetFlexFormSchema tool for FlexForm fields');
    }

    /**
     * Test that GetTableSchemaTool mentions plugin identifiers
     */
    public function testShowsPluginIdentifiers(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        // Get schema for the News plugin CType
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1',
        ]);

        $content = $result->content[0]->text;

        // Should show the plugin CType and FlexForm guidance
        self::assertStringContainsString('news_pi1', $content);
        self::assertStringContainsString('news_pi1', $content);

        // Should provide guidance on FlexForm discovery
        $hasFlexFormGuidance = str_contains((string)$content, 'FlexForm')
                               || str_contains((string)$content, 'flexform')
                               || str_contains((string)$content, 'GetFlexFormSchema');

        self::assertTrue($hasFlexFormGuidance, 'Schema should provide guidance about FlexForm fields');
    }

    /**
     * Test that default schema mentions available plugin types
     */
    public function testDefaultSchemaMentionsListType(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        // Get default schema without type
        $result = $tool->execute([
            'table' => 'tt_content',
        ]);

        $content = $result->content[0]->text;

        // Should list available types including the News plugin CType
        self::assertStringContainsString('news_pi1', $content);

        // Should mention that plugins may have FlexForm configuration
        $hasPluginInfo = str_contains((string)$content, 'plugin')
                        || str_contains((string)$content, 'Plugin');

        self::assertTrue($hasPluginInfo, 'Default schema should mention plugins');
    }
}
