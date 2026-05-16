<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'Latest News',
                'pi_flexform' => [
                    'settings' => [
                        'orderBy' => 'datetime',
                        'orderDirection' => 'desc',
                        'topNewsFirst' => '1',
                        'limit' => '10',
                        'offset' => '0',
                        'hidePagination' => '0',
                        'detailPid' => '20',
                        'listPid' => '15',
                        'backPid' => '1',
                        'startingpoint' => '10',
                        'recursive' => '2',
                        'categories' => '1,2',
                        'categoryConjunction' => 'or',
                        'includeSubCategories' => '1',
                        'dateField' => 'datetime',
                        'archiveRestriction' => 'active',
                        'timeRestriction' => '2678400',
                        'timeRestrictionHigh' => '0',
                        'templateLayout' => '100',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Verify FlexForm XML was stored in the database
        $piFlexform = $this->getRawFlexFormXml($pluginUid);
        self::assertNotEmpty($piFlexform, 'pi_flexform should contain XML data');
        self::assertStringContainsString('datetime', $piFlexform);
        self::assertStringContainsString('desc', $piFlexform);

        // Read the plugin back with basic fields (excluding pi_flexform which
        // cannot be parsed by ReadTable due to FlexFormService constructor changes)
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $pluginUid,
            'fields' => ['CType', 'header'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $plugin = json_decode((string)$result->content[0]->text, true)['records'][0];

        self::assertEquals('news_pi1', $plugin['CType']);
        self::assertEquals('Latest News', $plugin['header']);
    }

    /**
     * Test updating News plugin FlexForm settings
     */
    public function testUpdateNewsPluginFlexForm(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

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
        $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];

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

        // Verify updates via raw DB
        $piFlexform = $this->getRawFlexFormXml($pluginUid);
        self::assertStringContainsString('datetime', $piFlexform);
        self::assertStringContainsString('asc', $piFlexform);
        self::assertStringContainsString('20', $piFlexform);
        self::assertStringContainsString('1,2,3', $piFlexform);
        self::assertStringContainsString('and', $piFlexform);
        self::assertStringContainsString('25', $piFlexform);
        self::assertStringContainsString('200', $piFlexform);
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

            // Verify settings were stored via raw DB
            $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];
            $piFlexform = $this->getRawFlexFormXml($pluginUid);
            self::assertNotEmpty($piFlexform, "FlexForm XML should be stored for $modeName mode");

            foreach ($modeSettings as $key => $value) {
                self::assertStringContainsString(
                    'settings' . $key,
                    $piFlexform,
                    "Setting key '$key' not found in FlexForm XML for $modeName mode",
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
        $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];

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
     * Test GetFlexFormSchemaTool integration — in TYPO3 v14, CType-based plugins
     * do not register FlexForm DS via the column-level ds array, so identifier
     * lookup is expected to fail.
     */
    public function testGetFlexFormSchemaToolIntegration(): void
    {
        $schemaTool = GeneralUtility::makeInstance(GetFlexFormSchemaTool::class);

        $result = $schemaTool->execute([
            'table' => 'tt_content',
            'field' => 'pi_flexform',
            'identifier' => '*,news_pi1',
        ]);

        self::assertTrue($result->isError, 'FlexForm identifier lookup should fail for CType-based plugins in v14');
        self::assertStringContainsString('not found', $result->content[0]->text);
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
        $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];

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

        // Verify workspace version has the updates via raw DB
        $piFlexform = $this->getRawFlexFormXml($pluginUid);
        self::assertStringContainsString('15', $piFlexform);
        self::assertStringContainsString('datetime', $piFlexform);
        self::assertStringContainsString('desc', $piFlexform);
    }

    /**
     * Test complex nested FlexForm structures
     */
    public function testComplexNestedFlexFormStructures(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

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
                        'templateLayout' => '100',
                    ],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pluginUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Verify FlexForm data was stored via raw DB
        $piFlexform = $this->getRawFlexFormXml($pluginUid);
        self::assertNotEmpty($piFlexform);
        self::assertStringContainsString('datetime', $piFlexform);
        self::assertStringContainsString('10', $piFlexform);
        self::assertStringContainsString('100', $piFlexform);
    }

    /**
     * Get raw pi_flexform XML from database for a tt_content record.
     * Checks both workspace and live versions.
     */
    private function getRawFlexFormXml(int $uid): string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('pi_flexform')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                ),
            )
            ->orderBy('t3ver_wsid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return (string)($row['pi_flexform'] ?? '');
    }
}
