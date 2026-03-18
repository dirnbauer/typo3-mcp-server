<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test FlexForm handling with News plugin
 */
class NewsFlexFormTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_category.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test creating a News plugin with comprehensive FlexForm settings
     */
    public function testCreateNewsPluginWithFlexForm(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a News plugin with extensive FlexForm configuration
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'Latest News',
                'pi_flexform' => [
                    'settings' => [
                        // Display settings
                        'orderBy' => 'datetime',
                        'orderDirection' => 'desc',
                        'topNewsFirst' => '1',
                        'limit' => '10',
                        'offset' => '0',
                        'hidePagination' => '0',

                        // Page references
                        'detailPid' => '20',
                        'listPid' => '15',
                        'backPid' => '1',
                        'startingpoint' => '10',
                        'recursive' => '2',

                        // Category settings
                        'categories' => '1,2',
                        'categoryConjunction' => 'or',
                        'includeSubCategories' => '1',

                        // Date and archive settings
                        'dateField' => 'datetime',
                        'archiveRestriction' => 'active',
                        'timeRestriction' => '2678400', // 31 days
                        'timeRestrictionHigh' => '0',

                        // Template settings
                        'templateLayout' => '100',
                        'media' => [
                            'maxWidth' => '800',
                            'maxHeight' => '600',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Read the plugin back
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $plugin = json_decode($result->content[0]->text, true)['records'][0];

        // Verify basic fields
        self::assertEquals('news_pi1', $plugin['CType']);
        self::assertEquals('Latest News', $plugin['header']);

        // Verify FlexForm was converted from array and stored
        self::assertArrayHasKey('pi_flexform', $plugin);
        self::assertIsArray($plugin['pi_flexform']);

        // Verify FlexForm settings were preserved
        self::assertArrayHasKey('settings', $plugin['pi_flexform']);
        $settings = $plugin['pi_flexform']['settings'];

        // Check display settings
        self::assertEquals('datetime', $settings['orderBy']);
        self::assertEquals('desc', $settings['orderDirection']);
        self::assertEquals('1', $settings['topNewsFirst']);
        self::assertEquals('10', $settings['limit']);

        // Check page references
        self::assertEquals('20', $settings['detailPid']);
        self::assertEquals('15', $settings['listPid']);
        self::assertEquals('10', $settings['startingpoint']);

        // Check category settings
        self::assertEquals('1,2', $settings['categories']);
        self::assertEquals('or', $settings['categoryConjunction']);

        // Check nested media settings
        self::assertArrayHasKey('media', $settings);
        self::assertEquals('800', $settings['media']['maxWidth']);
        self::assertEquals('600', $settings['media']['maxHeight']);
    }

    /**
     * Test updating News plugin FlexForm settings
     */
    public function testUpdateNewsPluginFlexForm(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // First create a News plugin
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'News to Update',
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'title',
                        'limit' => '5',
                        'categories' => '1',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Update the FlexForm settings
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'datetime',
                        'orderDirection' => 'asc',
                        'limit' => '20',
                        'categories' => '1,2,3',
                        'categoryConjunction' => 'and',
                        'detailPid' => '25',
                        'templateLayout' => '200',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read and verify the update
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);

        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];

        // Verify updates
        self::assertEquals('datetime', $settings['orderBy']);
        self::assertEquals('asc', $settings['orderDirection']);
        self::assertEquals('20', $settings['limit']);
        self::assertEquals('1,2,3', $settings['categories']);
        self::assertEquals('and', $settings['categoryConjunction']);
        self::assertEquals('25', $settings['detailPid']);
        self::assertEquals('200', $settings['templateLayout']);
    }

    /**
     * Test different News plugin modes
     */
    public function testDifferentNewsPluginModes(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $modes = [
            'List' => [
                'switchableControllerActions' => 'News->list',
                'limit' => '10',
                'orderBy' => 'datetime',
            ],
            'Detail' => [
                'switchableControllerActions' => 'News->detail',
                'useStdWrap' => 'singleNews',
                'singleNews' => '123',
            ],
            'CategoryMenu' => [
                'switchableControllerActions' => 'Category->list',
                'categoryMenuStartingpoint' => '1',
                'categoryMenuShowEmpty' => '1',
            ],
            'TagList' => [
                'switchableControllerActions' => 'Tag->list',
                'listPid' => '15',
            ],
        ];

        foreach ($modes as $modeName => $modeSettings) {
            // Create plugin with specific mode
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => 1,
                'data' => [
                    'CType' => 'news_pi1',
                    'header' => "News Plugin - $modeName Mode",
                    'pi_flexform' => [
                        'settings' => $modeSettings,
                    ],
                ],
            ]);

            self::assertFalse($result->isError, "Failed to create $modeName mode: " . json_encode($result->jsonSerialize()));

            // Read back and verify
            $pluginUid = json_decode($result->content[0]->text, true)['uid'];
            $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
            $result = $readTool->execute([
                'table' => 'tt_content',
                'uid' => $pluginUid,
            ]);

            $plugin = json_decode($result->content[0]->text, true)['records'][0];
            self::assertArrayHasKey('pi_flexform', $plugin);
            self::assertArrayHasKey('settings', $plugin['pi_flexform']);

            // Verify mode-specific settings
            foreach ($modeSettings as $key => $value) {
                self::assertEquals(
                    $value,
                    $plugin['pi_flexform']['settings'][$key],
                    "Setting $key not preserved for $modeName mode",
                );
            }
        }
    }

    /**
     * Test empty FlexForm handling
     */
    public function testEmptyFlexFormHandling(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create plugin with empty FlexForm
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'News Plugin with Empty FlexForm',
                'pi_flexform' => [],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Update with empty settings
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => [],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test GetFlexFormSchemaTool integration
     */
    public function testGetFlexFormSchemaToolIntegration(): void
    {
        // First create a News plugin
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'News Plugin for Schema Test',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Get FlexForm schema
        $schemaTool = GeneralUtility::makeInstance(GetFlexFormSchemaTool::class);
        $result = $schemaTool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'recordUid' => $pluginUid,
            'identifier' => '*,news_pi1',  // News uses this pattern
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify schema contains News-specific settings
        self::assertStringContainsString('orderBy', $content);
        self::assertStringContainsString('orderDirection', $content);
        self::assertStringContainsString('categories', $content);
        self::assertStringContainsString('detailPid', $content);
        self::assertStringContainsString('listPid', $content);

        // Check for sheet structure
        self::assertStringContainsString('SHEETS:', $content);
    }

    /**
     * Test workspace handling for FlexForm updates
     */
    public function testFlexFormWorkspaceHandling(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create plugin in workspace
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'Workspace FlexForm Test',
                'pi_flexform' => [
                    'settings' => [
                        'limit' => '5',
                        'orderBy' => 'title',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Update in workspace
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $pluginUid,
            'data' => [
                'pi_flexform' => [
                    'settings' => [
                        'limit' => '15',
                        'orderBy' => 'datetime',
                        'orderDirection' => 'desc',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify workspace version has the updates
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);

        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];

        self::assertEquals('15', $settings['limit']);
        self::assertEquals('datetime', $settings['orderBy']);
        self::assertEquals('desc', $settings['orderDirection']);
    }

    /**
     * Test complex nested FlexForm structures
     */
    public function testComplexNestedFlexFormStructures(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create plugin with complex nested structures
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'Complex FlexForm Test',
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'datetime',
                        'limit' => '10',
                        // Nested media configuration
                        'media' => [
                            'image' => [
                                'maxWidth' => '1200',
                                'maxHeight' => '800',
                                'lightbox' => [
                                    'enabled' => '1',
                                    'class' => 'lightbox',
                                    'width' => '1920',
                                    'height' => '1080',
                                ],
                            ],
                            'video' => [
                                'width' => '16',
                                'height' => '9',
                                'autoplay' => '0',
                            ],
                        ],
                        // List view configuration
                        'list' => [
                            'media' => [
                                'dummyImage' => '1',
                                'image' => [
                                    'maxWidth' => '400',
                                    'maxHeight' => '300',
                                ],
                            ],
                            'paginate' => [
                                'itemsPerPage' => '10',
                                'insertAbove' => '1',
                                'insertBelow' => '1',
                                'maximumNumberOfLinks' => '5',
                            ],
                        ],
                        // Detail view configuration
                        'detail' => [
                            'media' => [
                                'image' => [
                                    'maxWidth' => '800',
                                ],
                            ],
                            'showSocialShareButtons' => '1',
                            'showPrevNext' => '1',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode($result->content[0]->text, true)['uid'];

        // Read and verify nested structures
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
        ]);

        $plugin = json_decode($result->content[0]->text, true)['records'][0];
        $settings = $plugin['pi_flexform']['settings'];

        // Verify deep nesting
        self::assertArrayHasKey('media', $settings);
        self::assertArrayHasKey('image', $settings['media']);
        self::assertArrayHasKey('lightbox', $settings['media']['image']);
        self::assertEquals('1', $settings['media']['image']['lightbox']['enabled']);
        self::assertEquals('1920', $settings['media']['image']['lightbox']['width']);

        // Verify list configuration
        self::assertArrayHasKey('list', $settings);
        self::assertArrayHasKey('paginate', $settings['list']);
        self::assertEquals('10', $settings['list']['paginate']['itemsPerPage']);

        // Verify detail configuration
        self::assertArrayHasKey('detail', $settings);
        self::assertEquals('1', $settings['detail']['showSocialShareButtons']);
    }
}
