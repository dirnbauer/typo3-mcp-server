<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\RollbackWorkspaceTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\Connection;

final class RollbackWorkspaceToolTest extends AbstractFunctionalTest
{
    private RollbackWorkspaceTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(RollbackWorkspaceTool::class);
    }

    public function testDryRunReturnsPreviewOfPendingChanges(): void
    {
        $workspaceId = $this->createWorkspacePageChange('Rollback dry-run page');

        $result = $this->tool->execute(['workspace_id' => $workspaceId]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertTrue($data['dryRun']);
        self::assertSame($workspaceId, $data['workspaceId']);
        self::assertGreaterThan(0, $data['totalRecords']);
        self::assertArrayHasKey('pages', $data['tables']);
    }

    public function testExecuteDiscardsWorkspaceChanges(): void
    {
        $workspaceId = $this->createWorkspacePageChange('Rollback execute page');
        self::assertTrue($this->workspaceVersionExists('pages', 1, $workspaceId));

        $result = $this->tool->execute([
            'workspace_id' => $workspaceId,
            'dryRun' => false,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertFalse($data['dryRun']);
        self::assertTrue($data['discarded']);
        self::assertSame($workspaceId, $data['workspaceId']);
        self::assertFalse($this->workspaceVersionExists('pages', 1, $workspaceId));
    }

    public function testUidFilterRequiresTable(): void
    {
        $workspaceId = $this->createWorkspacePageChange('Rollback uid filter page');

        $result = $this->tool->execute([
            'workspace_id' => $workspaceId,
            'uid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('"uid" parameter requires "table"', $this->getFirstTextContent($result));
    }

    private function createWorkspacePageChange(string $title): int
    {
        $workspaceId = $this->createAndSwitchToWorkspace('Rollback Tool Test');
        $writeTool = $this->getService(WriteTableTool::class);

        $result = $writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => ['title' => $title],
            'workspace_id' => $workspaceId,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return $workspaceId;
    }

    private function workspaceVersionExists(string $table, int $liveUid, int $workspaceId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $record !== false && $record !== null;
    }
}
