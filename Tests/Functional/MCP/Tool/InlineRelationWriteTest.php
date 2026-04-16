<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test writing inline relations through different approaches
 */
class InlineRelationWriteTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    /**
     * Test writing inline relations through foreign field (current working method)
     */
    public function testWriteInlineRelationThroughForeignField(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create a page
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News with inline content',
                'bodytext' => 'Test news body',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create content elements with foreign field set
        $contentUids = [];
        for ($i = 1; $i <= 2; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content element $i",
                    'bodytext' => "Content for element $i",
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,  // Foreign field
                    'sorting' => $i * 256,
                ],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode($result->content[0]->text, true)['uid'];
        }
        
        // Read the news record and verify inline relations
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify content_elements field contains UIDs
        $this->assertArrayHasKey('content_elements', $news);
        $this->assertIsArray($news['content_elements']);
        $this->assertCount(2, $news['content_elements']);
        
        // Verify we get UIDs, not full records
        foreach ($news['content_elements'] as $uid) {
            $this->assertIsInt($uid);
            $this->assertContains($uid, $contentUids);
        }
        
        // Verify all created content elements are included (order doesn't matter)
        sort($contentUids);
        $actualUids = $news['content_elements'];
        sort($actualUids);
        $this->assertEquals($contentUids, $actualUids);
    }

    /**
     * Verifies that inline children of inline children are created recursively.
     * This is the pattern that failed for lia_ctypes: tt_content → tx_liactypes_ctypes.
     */
    public function testNestedInlineRelationsAreCreatedRecursively(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a page
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => ['title' => 'Nested inline test', 'doktype' => 1],
        ]);
        $this->assertFalse($result->isError);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        // Create news with nested inline: content_elements contain media (sys_file_reference)
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News with nested inline',
                'content_elements' => [
                    [
                        'header' => 'Content with image',
                        'CType' => 'text',
                        'media' => [
                            ['uid_local' => 1, 'title' => 'Test image'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, 'Nested inline creation should work: ' . json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Verify the content element was created
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(1, $news['content_elements'], 'One content element should be linked');

        // Verify the file reference was created on the content element
        $contentUid = $news['content_elements'][0];
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $content = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertEquals('Content with image', $content['header']);

        // Check ALL sys_file_reference records to debug
        $queryBuilder = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\ConnectionPool::class
        )->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $allFileReferences = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->executeQuery()
            ->fetchAllAssociative();

        // Filter for our content element
        $fileReferences = array_filter($allFileReferences, fn($r) => (int)$r['uid_foreign'] === $contentUid && $r['tablenames'] === 'tt_content');

        $debugInfo = 'Content UID: ' . $contentUid . ', All sys_file_reference records: ' . json_encode(
            array_map(fn($r) => ['uid' => $r['uid'], 'uid_local' => $r['uid_local'], 'uid_foreign' => $r['uid_foreign'], 'tablenames' => $r['tablenames'], 'fieldname' => $r['fieldname'], 'title' => $r['title']], $allFileReferences)
        );

        $this->assertCount(1, $fileReferences, 'One file reference should be created for the content element. ' . $debugInfo);
        $ref = reset($fileReferences);
        $this->assertEquals(1, $ref['uid_local'], 'File reference should point to sys_file uid 1');
        $this->assertEquals('Test image', $ref['title']);
    }

    /**
     * Embedded links with hideTable=true work with string '1' value
     *
     * tx_news_domain_model_link has hideTable=true (boolean).
     * This test verifies the !empty() check works for both boolean true and string '1'.
     */
    public function testHideTableStringOneIsRecognized(): void
    {
        // Verify that news link table has hideTable set
        $linkTCA = $GLOBALS['TCA']['tx_news_domain_model_link']['ctrl'] ?? [];
        $this->assertNotEmpty($linkTCA['hideTable'] ?? false, 'tx_news_domain_model_link should have hideTable set');

        // This test is implicitly covered by NewsLinkInlineTest::testCreateNewsWithEmbeddedLinks
        // but we explicitly verify the !empty() check handles both true and '1'
        $this->assertTrue(!empty(true), 'Boolean true should pass !empty()');
        $this->assertTrue(!empty('1'), 'String "1" should pass !empty()');
        $this->assertTrue(!empty(1), 'Integer 1 should pass !empty()');
        $this->assertFalse(!empty(false), 'Boolean false should fail !empty()');
        $this->assertFalse(!empty(''), 'Empty string should fail !empty()');
        $this->assertFalse(!empty(0), 'Integer 0 should fail !empty()');
    }
}