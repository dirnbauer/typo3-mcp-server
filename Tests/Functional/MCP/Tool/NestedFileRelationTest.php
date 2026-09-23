<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/** Regression coverage adapted from upstream #128 for the fork's single DataHandler run. */
final class NestedFileRelationTest extends AbstractFunctionalTest
{
    private const string ITEM_TABLE = 'tx_testnestedfiles_item';

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_nested_files',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
    }

    public function testCreateResolvesFilesOnEachEmbeddedChildInArrayOrder(): void
    {
        $uid = $this->createElement([
            ['title' => 'First', 'file' => [['uid_local' => 1, 'alternative' => 'First image']]],
            ['title' => 'Second', 'file' => [2]],
        ]);

        $items = $this->items($uid);
        self::assertSame(['First', 'Second'], array_column($items, 'title'));
        $this->assertReference((int)$items[0]['uid'], 1, 'First image');
        $this->assertReference((int)$items[1]['uid'], 2);
    }

    public function testUpdateCanAttachReplacePatchAndRemoveNestedFiles(): void
    {
        $uid = $this->createElement([['title' => 'Item']]);
        $itemUid = (int)$this->items($uid)[0]['uid'];

        $this->updateItems($uid, [['uid' => $itemUid, 'file' => [1]]]);
        $this->assertReference($itemUid, 1);

        $this->updateItems($uid, [['uid' => $itemUid, 'file' => [['uid_local' => 2]]]]);
        $reference = $this->assertReference($itemUid, 2);

        $this->updateItems($uid, [['uid' => $itemUid, 'file' => [['uid' => $reference, 'alternative' => 'Patched']]]]);
        self::assertSame($reference, $this->assertReference($itemUid, 2, 'Patched'));

        $this->updateItems($uid, [['uid' => $itemUid, 'file' => []]]);
        self::assertSame([], $this->references($itemUid));
    }

    public function testFilesResolveAndCanBeRemovedMoreThanOneLevelDeep(): void
    {
        $uid = $this->createElement([['title' => 'Parent item', 'children' => [
            ['title' => 'Nested item', 'file' => [1]],
        ]]]);
        $itemUid = (int)$this->items($uid)[0]['uid'];
        $children = $this->effectiveChildren(self::ITEM_TABLE, 'parent_item', $itemUid, 'sorting');
        self::assertCount(1, $children);
        $nestedUid = (int)$children[0]['uid'];
        $this->assertReference($nestedUid, 1);

        $this->updateItems($uid, [['uid' => $itemUid, 'children' => [
            ['uid' => $nestedUid, 'file' => []],
        ]]]);
        self::assertSame([], $this->references($nestedUid));
    }

    public function testNestedReferenceCannotBeTakenFromAnotherChild(): void
    {
        $uid = $this->createElement([
            ['title' => 'First', 'file' => [1]],
            ['title' => 'Second', 'file' => [2]],
        ]);
        [$first, $second] = $this->items($uid);
        $firstUid = (int)$first['uid'];
        $secondUid = (int)$second['uid'];
        $reference = $this->assertReference($firstUid, 1);

        $result = $this->getService(WriteTableTool::class)->execute([
            'action' => 'update', 'table' => 'tt_content', 'uid' => $uid,
            'data' => ['tx_testnestedfiles_items' => [
                ['uid' => $firstUid, 'file' => []],
                ['uid' => $secondUid, 'file' => [['uid' => $reference]]],
            ]],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not belong', $result->content[0]->text);
        self::assertSame($reference, $this->assertReference($firstUid, 1));
        $this->assertReference($secondUid, 2);
    }

    public function testInvalidNestedPayloadDoesNotDeleteExistingFiles(): void
    {
        $uid = $this->createElement([['title' => 'Item', 'file' => [1]]]);
        $itemUid = (int)$this->items($uid)[0]['uid'];
        $reference = $this->assertReference($itemUid, 1);

        $result = $this->getService(WriteTableTool::class)->execute([
            'action' => 'update', 'table' => 'tt_content', 'uid' => $uid,
            'data' => ['tx_testnestedfiles_items' => [
                ['uid' => $itemUid, 'file' => [['uid_local' => 2], 'invalid']],
            ]],
        ]);

        self::assertTrue($result->isError);
        self::assertSame($reference, $this->assertReference($itemUid, 1));
    }

    public function testUpdatesOfLiveChildrenLeaveLiveReferencesUntouched(): void
    {
        $this->getConnectionForTable(self::ITEM_TABLE)->insert(self::ITEM_TABLE, [
            'uid' => 100, 'pid' => 1, 'title' => 'Live item', 'tt_content_items' => 100, 'file' => 1,
        ]);
        $this->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 100, 'pid' => 1, 'uid_local' => 1, 'uid_foreign' => 100,
            'tablenames' => self::ITEM_TABLE, 'fieldname' => 'file', 'alternative' => 'Live alternative',
        ]);
        $this->getConnectionForTable('tt_content')->update('tt_content', [
            'CType' => 'textmedia', 'tx_testnestedfiles_items' => 1,
        ], ['uid' => 100]);

        $this->updateItems(100, [['uid' => 100, 'file' => [['uid' => 100, 'alternative' => 'Draft only']]]]);
        $this->assertReference(100, 1, 'Draft only');
        $live = $this->getConnectionForTable('sys_file_reference')->select(
            ['alternative', 'deleted', 't3ver_wsid'],
            'sys_file_reference',
            ['uid' => 100],
        )->fetchAssociative();
        self::assertIsArray($live, 'The live reference is missing');
        self::assertSame('Live alternative', $live['alternative']);
        self::assertSame(0, (int)$live['deleted']);
        self::assertSame(0, (int)$live['t3ver_wsid']);

        $this->updateItems(100, [['uid' => 100, 'file' => [2]]]);
        $this->assertReference(100, 2);
        $live = $this->getConnectionForTable('sys_file_reference')->select(
            ['uid_local', 'deleted'],
            'sys_file_reference',
            ['uid' => 100],
        )->fetchAssociative();
        self::assertIsArray($live, 'The live reference is missing');
        self::assertSame(1, (int)$live['uid_local']);
        self::assertSame(0, (int)$live['deleted']);
    }

    /**
     * The /mcp endpoint publishes its frontend-stack request. The write must
     * not run with frontend file handling (FileRepository, storages), and
     * the endpoint's request is back in place afterwards.
     */
    public function testUpdatesOfLiveChildrenWorkWhileTheEndpointRequestIsActive(): void
    {
        $serverParams = ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/mcp'];
        $endpointRequest = new ServerRequest('https://example.com/mcp', 'POST', 'php://input', [], $serverParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $endpointRequest;

        try {
            $this->testUpdatesOfLiveChildrenLeaveLiveReferencesUntouched();
            self::assertSame($endpointRequest, $GLOBALS['TYPO3_REQUEST'] ?? null);
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }

    /**
     * @param list<array<string, mixed>> $items tx_testnestedfiles_items payload
     */
    private function createElement(array $items): int
    {
        $result = $this->getService(WriteTableTool::class)->execute([
            'action' => 'create', 'table' => 'tt_content', 'pid' => 1,
            'data' => ['CType' => 'textmedia', 'header' => 'Nested files', 'tx_testnestedfiles_items' => $items],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        return (int)$this->extractJsonFromResult($result)['uid'];
    }

    /**
     * @param list<array<string, mixed>> $items tx_testnestedfiles_items payload
     */
    private function updateItems(int $uid, array $items): void
    {
        $result = $this->getService(WriteTableTool::class)->execute([
            'action' => 'update', 'table' => 'tt_content', 'uid' => $uid,
            'data' => ['tx_testnestedfiles_items' => $items],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(int $uid): array
    {
        return $this->effectiveChildren(self::ITEM_TABLE, 'tt_content_items', $uid, 'sorting');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function references(int $uid): array
    {
        return array_values(array_filter(
            $this->effectiveChildren('sys_file_reference', 'uid_foreign', $uid, 'sorting_foreign'),
            static fn(array $row): bool => $row['tablenames'] === self::ITEM_TABLE && $row['fieldname'] === 'file',
        ));
    }

    private function assertReference(int $itemUid, int $fileUid, ?string $alternative = null): int
    {
        $references = $this->references($itemUid);
        self::assertCount(1, $references);
        self::assertSame($fileUid, (int)$references[0]['uid_local']);
        if ($alternative !== null) {
            self::assertSame($alternative, $references[0]['alternative']);
        }
        return (int)$references[0]['uid'];
    }

    /**
     * @return list<array<string, mixed>> Workspace-overlaid children of $parentUid, ordered by $sorting
     */
    private function effectiveChildren(string $table, string $foreignField, int $parentUid, string $sorting): array
    {
        $rows = $this->getConnectionForTable($table)->select(['*'], $table, ['t3ver_oid' => 0])->fetchAllAssociative();
        $children = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL($table, $row);
            if (is_array($row) && !(bool)$row['deleted'] && (int)$row['t3ver_state'] !== 2 && (int)$row[$foreignField] === $parentUid) {
                $children[] = $row;
            }
        }
        usort($children, static fn(array $a, array $b): int => (int)$a[$sorting] <=> (int)$b[$sorting]);
        return $children;
    }
}
