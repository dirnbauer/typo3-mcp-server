<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Review pending changes in a workspace before publishing.
 *
 * @phpstan-type WorkspaceChange array<string, mixed>
 */
final class WorkspaceReviewTool extends AbstractRecordTool
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Review all pending changes in a workspace. '
                . 'Shows new, modified, deleted, and moved records with a diff of changed fields. '
                . 'Use this to inspect what will be published before making changes live. '
                . 'Essential for the draft → review → publish workflow.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: filter to changes in a specific table only',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of changes to return (default: 100, max: 500)',
                        'default' => self::DEFAULT_LIMIT,
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Offset for pagination (default: 0)',
                        'default' => 0,
                        'minimum' => 0,
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        $workspaceId = $backendUser->workspace ?? 0;
        if ($workspaceId === 0) {
            return $this->createErrorResult('Cannot review changes in the live workspace. Switch to a draft workspace first or provide workspace_id.');
        }

        $filterTable = \is_string($params['table'] ?? null) ? trim($params['table']) : '';
        $limit = is_numeric($params['limit'] ?? null) ? min((int)$params['limit'], self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $offset = is_numeric($params['offset'] ?? null) ? max((int)$params['offset'], 0) : 0;

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        // Get workspace title
        $wsTitle = $this->getWorkspaceTitle($workspaceId);

        // Get tables to scan
        $tablesToScan = $this->getTablesToScan($filterTable);

        $allChanges = [];
        $summary = [];
        $totalChanges = 0;

        foreach ($tablesToScan as $table) {
            $changes = $this->getWorkspaceChangesForTable($table, $workspaceId);
            if ($changes !== []) {
                $summary[$table] = \count($changes);
                $totalChanges += \count($changes);
                $allChanges[$table] = $changes;
            }
        }

        // Apply pagination across all tables
        $flatChanges = [];
        foreach ($allChanges as $table => $changes) {
            foreach ($changes as $change) {
                $change['_table'] = $table;
                $flatChanges[] = $change;
            }
        }

        $paginatedChanges = \array_slice($flatChanges, $offset, $limit);

        // Re-group by table
        $groupedChanges = [];
        foreach ($paginatedChanges as $change) {
            $table = \is_string($change['_table'] ?? null) ? $change['_table'] : '';
            unset($change['_table']);
            $groupedChanges[$table][] = $change;
        }

        $returned = \count($paginatedChanges);
        $hasMore = ($offset + $returned) < $totalChanges;

        $result = [
            'workspaceId' => $workspaceId,
            'workspaceTitle' => $wsTitle,
            'changes' => $groupedChanges,
            'summary' => $summary,
            'totalChanges' => $totalChanges,
            'returned' => $returned,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $hasMore,
        ];

        if ($hasMore) {
            $result['nextOffset'] = $offset + $returned;
        }

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)]);
    }

    private function getWorkspaceTitle(int $workspaceId): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $qb->select('title')
            ->from('sys_workspace')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($workspaceId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return \is_array($row) && \is_scalar($row['title'] ?? null) ? (string)$row['title'] : 'Workspace #' . $workspaceId;
    }

    /**
     * Get the list of workspace-capable tables to scan.
     *
     * @return list<string>
     */
    private function getTablesToScan(string $filterTable): array
    {
        if ($filterTable !== '') {
            if (!$this->tableAccessService->canAccessTable($filterTable)) {
                return [];
            }
            $accessInfo = $this->tableAccessService->getTableAccessInfo($filterTable);
            if (!$accessInfo['workspace_capable']) {
                return [];
            }
            return [$filterTable];
        }

        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        $tables = [];

        foreach ($accessibleTables as $table => $label) {
            $accessInfo = $this->tableAccessService->getTableAccessInfo($table);
            if ($accessInfo['workspace_capable']) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Get all workspace changes for a specific table.
     *
     * @return list<WorkspaceChange>
     */
    private function getWorkspaceChangesForTable(string $table, int $workspaceId): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca) || !isset($globalTca[$table])) {
            return [];
        }

        $tableConfig = $globalTca[$table];
        if (!\is_array($tableConfig)) {
            return [];
        }

        $ctrl = $tableConfig['ctrl'] ?? [];
        if (!\is_array($ctrl)) {
            return [];
        }

        // Table must have workspace fields
        if (!isset($ctrl['versioningWS']) || !$ctrl['versioningWS']) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($workspaceId, Connection::PARAM_INT)),
                // t3ver_oid > 0 means it's a version of an existing record
                // t3ver_oid = 0 with t3ver_wsid > 0 means new placeholder in workspace
            );

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $labelField = \is_string($ctrl['label'] ?? null) ? $ctrl['label'] : '';
        $changes = [];

        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
            $liveUid = is_numeric($row['t3ver_oid'] ?? null) ? (int)$row['t3ver_oid'] : 0;
            $versionState = is_numeric($row['t3ver_state'] ?? null) ? (int)$row['t3ver_state'] : 0;
            $pid = is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0;

            $state = match ($versionState) {
                -1 => 'new_placeholder',
                1 => 'new',
                2 => 'deleted',
                4 => 'moved',
                default => $liveUid > 0 ? 'modified' : 'new',
            };

            $label = '';
            if ($labelField !== '' && \is_scalar($row[$labelField] ?? null)) {
                $label = (string)$row[$labelField];
            }

            $change = [
                'uid' => $uid,
                'liveUid' => $liveUid,
                'state' => $state,
                'pid' => $pid,
                'label' => $label,
            ];

            // For modified records, show the diff
            if ($state === 'modified' && $liveUid > 0) {
                $diff = $this->computeDiff($table, $liveUid, $row);
                if ($diff !== []) {
                    $change['modifiedFields'] = $diff;
                }
            }

            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * Compute the diff between live and workspace version of a record.
     *
     * @param array<string, mixed> $wsRow
     * @return array<string, array{live: mixed, draft: mixed}>
     */
    private function computeDiff(string $table, int $liveUid, array $wsRow): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $liveRow = $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($liveUid, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!\is_array($liveRow)) {
            return [];
        }

        // Fields to skip in diff
        $skipFields = [
            'uid', 'pid', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
            'tstamp', 'crdate', 'sorting',
        ];

        $diff = [];
        foreach ($wsRow as $field => $wsValue) {
            if (!\is_string($field)) {
                continue;
            }
            if (\in_array($field, $skipFields, true)) {
                continue;
            }
            if (!array_key_exists($field, $liveRow)) {
                continue;
            }

            $liveValue = $liveRow[$field];

            // Compare as strings to handle type differences
            $liveStr = \is_scalar($liveValue) ? (string)$liveValue : '';
            $wsStr = \is_scalar($wsValue) ? (string)$wsValue : '';

            if ($liveStr !== $wsStr) {
                // Truncate long values for readability
                $diff[$field] = [
                    'live' => mb_strlen($liveStr) > 200 ? mb_substr($liveStr, 0, 200) . '...' : $liveStr,
                    'draft' => mb_strlen($wsStr) > 200 ? mb_substr($wsStr, 0, 200) . '...' : $wsStr,
                ];
            }
        }

        return $diff;
    }
}
