<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\BulkWriteTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A delete that is repeated in the same workspace must not undo itself.
 *
 * TYPO3's DataHandler treats a delete command on a record that already carries
 * a delete placeholder like the waste-bin toggle in the backend: it discards
 * the placeholder and the record re-appears. An LLM client that retries a
 * delete after a transient error therefore restored the record it had just
 * deleted, while the tool reported success both times.
 *
 * WriteTable and BulkWrite therefore treat a record that already carries a
 * delete placeholder in the current workspace as deleted.
 *
 * Adapted from upstream hauptsacheNet/typo3-mcp-server#68.
 */
final class WriteTableDeleteIdempotencyTest extends FunctionalTestCase
{
    use DevSiteTestTrait;
    use GetServiceTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableDevSiteTools();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->setUpBackendUser(1);

        $this->writeTool = $this->getService(WriteTableTool::class);
    }

    public function testRetriedDeleteKeepsTheRecordDeletedAndSiblingDraftsIntact(): void
    {
        $targetUid = 100;
        $siblingUid = 101;

        $siblingDraft = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $siblingUid,
            'data' => ['bodytext' => 'Sibling edited in workspace'],
        ]);
        self::assertFalse($siblingDraft->isError, (string)json_encode($siblingDraft->jsonSerialize()));

        $workspaceId = $this->getService(WorkspaceContextService::class)->getCurrentWorkspace();
        self::assertGreaterThan(0, $workspaceId, 'The test must run inside a workspace');
        self::assertSame(1, $this->countWorkspaceRows($siblingUid, $workspaceId), 'Baseline: the sibling has one draft');

        $firstDelete = $this->deleteContent($targetUid);
        self::assertFalse($firstDelete->isError, (string)json_encode($firstDelete->jsonSerialize()));
        self::assertSame(1, $this->countDeletePlaceholders($targetUid, $workspaceId), 'The first delete creates one placeholder');

        $retry = $this->deleteContent($targetUid);
        self::assertFalse($retry->isError, (string)json_encode($retry->jsonSerialize()));
        self::assertSame(
            ['action' => 'delete', 'table' => 'tt_content', 'uid' => $targetUid],
            json_decode((string)$retry->content[0]->text, true),
            'A retried delete answers like the first one',
        );

        self::assertSame(1, $this->countDeletePlaceholders($targetUid, $workspaceId), 'The retry must not discard the placeholder');
        self::assertSame(1, $this->countWorkspaceRows($siblingUid, $workspaceId), 'The sibling draft survives the retry');
        self::assertSame([], $this->readVisibleUids($targetUid), 'The record stays deleted in the workspace');
    }

    public function testRetriedBulkDeleteKeepsTheRecordDeleted(): void
    {
        $targetUid = 100;
        $bulkWrite = $this->getService(BulkWriteTool::class);
        $operations = [['action' => 'delete', 'table' => 'tt_content', 'uid' => $targetUid]];

        $firstDelete = $bulkWrite->execute(['operations' => $operations]);
        self::assertFalse($firstDelete->isError, (string)json_encode($firstDelete->jsonSerialize()));
        $workspaceId = $this->getService(WorkspaceContextService::class)->getCurrentWorkspace();
        self::assertGreaterThan(0, $workspaceId, 'The test must run inside a workspace');
        self::assertSame(1, $this->countDeletePlaceholders($targetUid, $workspaceId));

        $retry = $bulkWrite->execute(['operations' => $operations]);
        self::assertFalse($retry->isError, (string)json_encode($retry->jsonSerialize()));
        $retryData = json_decode((string)$retry->content[0]->text, true);
        self::assertIsArray($retryData);
        self::assertSame(1, $retryData['successCount'] ?? null);
        self::assertSame(0, $retryData['errorCount'] ?? null);

        self::assertSame(1, $this->countDeletePlaceholders($targetUid, $workspaceId), 'The retry must not discard the placeholder');
        self::assertSame([], $this->readVisibleUids($targetUid), 'The record stays deleted in the workspace');
    }

    private function deleteContent(int $uid): CallToolResult
    {
        return $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
    }

    /**
     * @return list<int>
     */
    private function readVisibleUids(int $uid): array
    {
        $result = $this->getService(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        self::assertFalse($result->isError, (string)json_encode($result->jsonSerialize()));
        $decoded = json_decode((string)$result->content[0]->text, true);
        self::assertIsArray($decoded);

        $uids = [];
        foreach (is_array($decoded['records'] ?? null) ? $decoded['records'] : [] as $record) {
            if (is_array($record) && is_numeric($record['uid'] ?? null)) {
                $uids[] = (int)$record['uid'];
            }
        }

        return $uids;
    }

    private function countWorkspaceRows(int $liveUid, int $workspaceId): int
    {
        $queryBuilder = $this->getService(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function countDeletePlaceholders(int $liveUid, int $workspaceId): int
    {
        $queryBuilder = $this->getService(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(2, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
