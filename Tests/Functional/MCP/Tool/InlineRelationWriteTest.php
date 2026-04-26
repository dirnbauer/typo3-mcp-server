<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode((string)$result->content[0]->text, true)['uid'];

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
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

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
            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode((string)$result->content[0]->text, true)['uid'];
        }

        // Read the news record and verify inline relations
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];

        // Verify content_elements field contains UIDs
        self::assertArrayHasKey('content_elements', $news);
        self::assertIsArray($news['content_elements']);
        self::assertCount(2, $news['content_elements']);

        // Verify we get UIDs, not full records
        foreach ($news['content_elements'] as $uid) {
            self::assertIsInt($uid);
            self::assertContains($uid, $contentUids);
        }

        // Verify all created content elements are included (order doesn't matter)
        sort($contentUids);
        $actualUids = $news['content_elements'];
        sort($actualUids);
        self::assertEquals($contentUids, $actualUids);
    }

    /**
     * Test writing inline relations for hidden tables (sys_file_reference)
     */
    public function testWriteHiddenTableInlineRelation(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'action' => 'create',
            'table' => 'sys_file_reference',
            'pid' => 1,
            'data' => [
                'uid_local' => 1,
                'uid_foreign' => 1,
                'tablenames' => 'tt_content',
                'fieldname' => 'assets',
            ],
        ]);

        if ($result->isError) {
            $text = strtolower((string)$result->content[0]->text);
            self::assertStringNotContainsString(
                'table is restricted for security or system integrity',
                $text,
                'sys_file_reference is a workspace FAL table; errors should be validation/DataHandler, not a blanket "restricted" deny.',
            );
        } else {
            self::assertIsArray($json = json_decode($result->content[0]->text, true) ?? null);
        }
    }

    /**
     * Test updating inline relations through parent record using UID arrays
     */
    public function testWriteInlineRelationThroughParentUsingUids(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a page first
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News to update with inline content',
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create content elements separately
        $contentUids = [];
        for ($i = 1; $i <= 3; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content element $i",
                    'CType' => 'text',
                ],
            ]);
            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode((string)$result->content[0]->text, true)['uid'];
        }

        // Now update the news record with inline content_elements using UIDs
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => $contentUids,  // Array of UIDs
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify the inline relations were set
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];

        self::assertArrayHasKey('content_elements', $news);
        self::assertIsArray($news['content_elements']);
        self::assertCount(3, $news['content_elements']);

        sort($contentUids);
        $actualUids = array_map(intval(...), $news['content_elements']);
        sort($actualUids);
        self::assertSame($contentUids, $actualUids);
    }

    /**
     * Test updating inline relations
     */
    public function testUpdateInlineRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create initial setup
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode((string)$result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News to update',
            ],
        ]);
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create initial content element
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Original content',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        $contentUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Update the content element to remove relation
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_news_related_news' => 0,  // Remove relation
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify relation is removed
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];
        self::assertArrayHasKey('content_elements', $news, 'Should have content_elements field');
        self::assertEmpty($news['content_elements'], 'content_elements should be empty after removal');
    }

    /**
     * Test writing inline relations with sorting
     */
    public function testInlineRelationSorting(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create page and news
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode((string)$result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News with sorted content',
            ],
        ]);
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create in this order; default "bottom" placement gives monotonic sorting (ASC by uid).
        $contentData = [
            ['header' => 'Third'],
            ['header' => 'Second'],
            ['header' => 'First'],
        ];

        $createdUids = [];
        foreach ($contentData as $data) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => array_merge($data, [
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,
                ]),
            ]);
            self::assertFalse($result->isError);
            $uid = json_decode((string)$result->content[0]->text, true)['uid'];
            $createdUids[$data['header']] = $uid;
        }

        // Read and verify sorting
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];
        self::assertArrayHasKey('content_elements', $news);
        self::assertCount(3, $news['content_elements']);

        // Check actual order
        $actualOrder = [];
        $sortingInfo = [];
        foreach ($news['content_elements'] as $uid) {
            // Get sorting value from database for debugging
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll();
            $record = $queryBuilder->select('header', 'sorting')
                ->from('tt_content')
                ->where($queryBuilder->expr()->eq('uid', $uid))
                ->executeQuery()
                ->fetchAssociative();

            $sortingInfo[$uid] = $record['sorting'];

            foreach ($createdUids as $header => $createdUid) {
                if ($uid == $createdUid) {
                    $actualOrder[] = $header;
                    break;
                }
            }
        }

        // Expected order = headers sorted by DB `sorting` ASC (same as TCA foreign_sortby).
        $expectedRows = [];
        foreach ($createdUids as $header => $cuid) {
            $qb = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            $qb->getRestrictions()->removeAll();
            $row = $qb->select('sorting')
                ->from('tt_content')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($cuid)))
                ->executeQuery()
                ->fetchAssociative();
            self::assertIsArray($row);
            $expectedRows[] = [
                'header' => $header,
                'sorting' => is_numeric($row['sorting'] ?? null) ? (int)$row['sorting'] : 0,
            ];
        }
        usort($expectedRows, static fn(array $a, array $b): int => $a['sorting'] <=> $b['sorting']);
        $expectedOrder = array_column($expectedRows, 'header');

        self::assertSame(
            $expectedOrder,
            $actualOrder,
            'content_elements must follow foreign_sortby (sorting ASC). '
            . 'Actual order: ' . json_encode($actualOrder) . ', '
            . 'UIDs: ' . json_encode($news['content_elements']) . ', '
            . 'Sorting values: ' . json_encode($sortingInfo),
        );
    }

    /**
     * Test partial update of inline relations (keeping some, removing others)
     */
    public function testPartialUpdateOfInlineRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create setup
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode((string)$result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News for partial update test',
            ],
        ]);
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create 4 content elements initially
        $allContentUids = [];
        for ($i = 1; $i <= 4; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content $i",
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,
                ],
            ]);
            self::assertFalse($result->isError);
            $allContentUids[] = json_decode((string)$result->content[0]->text, true)['uid'];
        }

        // Update news to keep only content elements 2 and 4
        $keptUids = [$allContentUids[1], $allContentUids[3]]; // indices 1 and 3
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => $keptUids,
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify only the specified UIDs remain linked and the others are unlinked.
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        $expectedRelations = [
            0 => 0,           // dropped
            1 => $newsUid,    // kept
            2 => 0,           // dropped
            3 => $newsUid,    // kept
        ];

        foreach ($expectedRelations as $index => $expectedRelation) {
            $result = $readTool->execute([
                'table' => 'tt_content',
                'uid' => $allContentUids[$index],
            ]);
            $response = json_decode((string)$result->content[0]->text, true);
            if (!isset($response['records'][0])) {
                self::fail("No record found for content element {$allContentUids[$index]}");
            }
            $content = $response['records'][0];
            if (!array_key_exists('tx_news_related_news', $content)) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('tt_content');
                $queryBuilder->getRestrictions()->removeAll();
                $dbRecord = $queryBuilder->select('tx_news_related_news')
                    ->from('tt_content')
                    ->where($queryBuilder->expr()->eq('uid', $allContentUids[$index]))
                    ->executeQuery()
                    ->fetchAssociative();
                $relatedNews = $dbRecord['tx_news_related_news'] ?? 0;
            } else {
                $relatedNews = $content['tx_news_related_news'];
            }
            $message = $expectedRelation === 0
                ? "Content element {$allContentUids[$index]} foreign field should be cleared after parent-side update"
                : "Content element {$allContentUids[$index]} foreign field should still reference news {$newsUid}";
            self::assertSame($expectedRelation, (int)$relatedNews, $message);
        }
    }

    /**
     * Test validation errors for inline relations
     */
    public function testInlineRelationValidationErrors(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $pageResult = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Validation Parent Page',
                'doktype' => 1,
            ],
        ]);
        self::assertFalse($pageResult->isError, json_encode($pageResult->jsonSerialize()) ?: '');
        $pageData = json_decode((string)$pageResult->content[0]->text, true);
        $pageUid = is_array($pageData) && isset($pageData['uid']) ? (int)$pageData['uid'] : 0;
        self::assertGreaterThan(0, $pageUid);

        // Create a news record first
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News for validation test',
            ],
        ]);
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Test 1: Non-array value
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => 'not-an-array',
            ],
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('must be an array of UIDs', $result->jsonSerialize()['content'][0]->text);

        // Test 2: Array with non-numeric values
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => [1, 'invalid', 3],
            ],
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('must be a record data array or a positive integer UID', $result->jsonSerialize()['content'][0]->text);

        // Test 3: Array with negative values
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => [1, -5, 3],
            ],
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('must be a record data array or a positive integer UID', $result->jsonSerialize()['content'][0]->text);

        // Test 4: Array with data objects (now accepted as inline record data)
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'content_elements' => [
                    ['header' => 'New content', 'CType' => 'text'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}
