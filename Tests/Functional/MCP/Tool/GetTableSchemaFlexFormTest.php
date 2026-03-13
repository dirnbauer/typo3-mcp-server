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
            'type' => 'news_pi1'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Check if pi_flexform appears
        $hasFlexForm = str_contains($content, 'pi_flexform');
        $this->assertTrue($hasFlexForm, 'Schema for list type should include pi_flexform field');
        
        $this->assertStringContainsString('news_pi1', $content);

        // Check if it mentions GetFlexFormSchema tool
        $mentionsFlexFormTool = str_contains($content, 'GetFlexFormSchema');
        $this->assertTrue($mentionsFlexFormTool, 'Schema should mention GetFlexFormSchema tool for FlexForm fields');
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
            'type' => 'news_pi1'
        ]);
        
        $content = $result->content[0]->text;
        
        // Should show the plugin CType and FlexForm guidance
        $this->assertStringContainsString('news_pi1', $content);
        $this->assertStringContainsString('news_pi1', $content);
        
        // Should provide guidance on FlexForm discovery
        $hasFlexFormGuidance = str_contains($content, 'FlexForm') || 
                               str_contains($content, 'flexform') ||
                               str_contains($content, 'GetFlexFormSchema');
        
        $this->assertTrue($hasFlexFormGuidance, 'Schema should provide guidance about FlexForm fields');
    }

    /**
     * Test that default schema mentions available plugin types
     */
    public function testDefaultSchemaMentionsListType(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        
        // Get default schema without type
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);
        
        $content = $result->content[0]->text;
        
        // Should list available types including the News plugin CType
        $this->assertStringContainsString('news_pi1', $content);
        
        // Should mention that plugins may have FlexForm configuration
        $hasPluginInfo = str_contains($content, 'plugin') || 
                        str_contains($content, 'Plugin');
        
        $this->assertTrue($hasPluginInfo, 'Default schema should mention plugins');
    }
}