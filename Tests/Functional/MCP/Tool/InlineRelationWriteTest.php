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
            self::assertIsArray($json = json_decode((string)$result->content[0]->text, true) ?? null);
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
    /**
     * Test patching an existing embedded inline relation by uid.
     *
     * Regression: payloads like `assets: [{uid: <existing>, title: "patched"}]` previously
     * ignored the uid and inserted a fresh sys_file_reference with uid_local=0 (broken),
     * leaving the original reference orphaned in the workspace.
     */
    public function testUpdateExistingEmbeddedRelationByUid(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Page + content element with one file reference (sys_file uid=1 from fixture)
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => ['title' => 'Page for ref-update', 'doktype' => 1],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Content with file reference',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'original'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Read back to capture the sys_file_reference uid
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $record = json_decode($result->content[0]->text, true)['records'][0];
        self::assertCount(1, $record['assets']);
        $originalRefUid = (int)$record['assets'][0]['uid'];
        self::assertSame(1, (int)$record['assets'][0]['uid_local']);
        self::assertSame('original', $record['assets'][0]['title']);

        // Patch the existing reference by passing uid + new field value
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    ['uid' => $originalRefUid, 'title' => 'patched'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify: same reference uid, title patched, uid_local preserved (not reset to 0)
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $record = json_decode($result->content[0]->text, true)['records'][0];
        self::assertCount(1, $record['assets'], 'No duplicate reference should be created');
        self::assertSame($originalRefUid, (int)$record['assets'][0]['uid'], 'Same sys_file_reference uid expected');
        self::assertSame('patched', $record['assets'][0]['title']);
        self::assertSame(1, (int)$record['assets'][0]['uid_local'], 'uid_local must not be wiped to 0');
    }

    /**
     * Embedded inline relations must not be stealable from another parent by uid.
     *
     * The update path that patches existing children by uid (testUpdateExistingEmbeddedRelationByUid)
     * could otherwise be abused: passing parent B's child uid into parent A's update would mutate
     * B's row and — through the orphan-deletion of children not in the new list — silently wipe
     * parent A's real children too.
     */
    public function testCannotStealEmbeddedRelationFromAnotherParent(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => ['title' => 'Page for steal-test', 'doktype' => 1],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        // Parent A with its own asset
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Parent A',
                'CType' => 'textmedia',
                'assets' => [['uid_local' => 1, 'title' => 'A-original']],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $parentAUid = json_decode($result->content[0]->text, true)['uid'];

        // Parent B with its own asset
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Parent B',
                'CType' => 'textmedia',
                'assets' => [['uid_local' => 1, 'title' => 'B-original']],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $parentBUid = json_decode($result->content[0]->text, true)['uid'];

        $aRef = (int)json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $parentAUid])->content[0]->text,
            true
        )['records'][0]['assets'][0]['uid'];
        $bRef = (int)json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $parentBUid])->content[0]->text,
            true
        )['records'][0]['assets'][0]['uid'];
        self::assertNotSame($aRef, $bRef);

        // Attempt to "steal" parent B's reference by patching it under parent A
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $parentAUid,
            'data' => [
                'assets' => [['uid' => $bRef, 'title' => 'stolen']],
            ],
        ]);
        self::assertTrue($result->isError, 'Stealing a child uid from another parent must be rejected');
        self::assertStringContainsString('does not belong to the current parent', $result->jsonSerialize()['content'][0]->text);

        // Parent A keeps its original reference unchanged
        $aAfter = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $parentAUid])->content[0]->text,
            true
        )['records'][0]['assets'];
        self::assertCount(1, $aAfter, 'Parent A must keep its original reference');
        self::assertSame($aRef, (int)$aAfter[0]['uid']);
        self::assertSame('A-original', $aAfter[0]['title']);

        // Parent B is untouched as well
        $bAfter = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $parentBUid])->content[0]->text,
            true
        )['records'][0]['assets'];
        self::assertCount(1, $bAfter, 'Parent B must keep its reference');
        self::assertSame($bRef, (int)$bAfter[0]['uid']);
        self::assertSame('B-original', $bAfter[0]['title']);
    }

    /**
     * The create path must not silently update an existing child by uid either.
     */
    public function testCreateRejectsExistingChildUid(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => ['title' => 'Page for create-steal-test', 'doktype' => 1],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Existing parent',
                'CType' => 'textmedia',
                'assets' => [['uid_local' => 1, 'title' => 'existing']],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $existingParentUid = json_decode($result->content[0]->text, true)['uid'];
        $existingRef = (int)json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $existingParentUid])->content[0]->text,
            true
        )['records'][0]['assets'][0]['uid'];

        // Create a new parent that tries to claim an existing child by uid
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Hijacker',
                'CType' => 'textmedia',
                'assets' => [['uid' => $existingRef, 'title' => 'hijacked']],
            ],
        ]);
        self::assertTrue($result->isError, 'Create must reject embedded children that reference an existing uid');
        self::assertStringContainsString('does not belong to the current parent', $result->jsonSerialize()['content'][0]->text);

        // Original parent is untouched
        $assets = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $existingParentUid])->content[0]->text,
            true
        )['records'][0]['assets'];
        self::assertCount(1, $assets);
        self::assertSame($existingRef, (int)$assets[0]['uid']);
        self::assertSame('existing', $assets[0]['title']);
    }

    /**
     * Reordering embedded children must follow array order.
     *
     * sorting_foreign is hidden from the write schema (auto-managed), so the only way
     * a caller can reorder embedded relations is by passing them in the desired order.
     */
    public function testReorderEmbeddedRelationsByArrayOrder(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => ['title' => 'Page for reorder', 'doktype' => 1],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        // Three references in order A, B, C
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Reorder me',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'A'],
                    ['uid_local' => 1, 'title' => 'B'],
                    ['uid_local' => 1, 'title' => 'C'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        $assets = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid])->content[0]->text,
            true
        )['records'][0]['assets'];
        self::assertSame(['A', 'B', 'C'], array_column($assets, 'title'));
        $byTitle = [];
        foreach ($assets as $asset) {
            $byTitle[$asset['title']] = (int)$asset['uid'];
        }

        // Reorder to C, A, B by passing only the uids in the new desired order.
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    ['uid' => $byTitle['C']],
                    ['uid' => $byTitle['A']],
                    ['uid' => $byTitle['B']],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $assets = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid])->content[0]->text,
            true
        )['records'][0]['assets'];
        self::assertCount(3, $assets, 'No references should be lost during reorder');
        self::assertSame(
            ['C', 'A', 'B'],
            array_column($assets, 'title'),
            'Embedded references must follow the order supplied in the update payload'
        );
    }
}
