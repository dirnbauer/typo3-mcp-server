<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Read TYPO3 system log entries for debugging.
 */
final class GetSystemLogTool extends AbstractRecordTool
{
    private const DEFAULT_LIMIT = 50;
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
            'description' => 'Read TYPO3 system log entries (sys_log) for debugging. '
                . 'Helps diagnose failed operations, permission errors, and recent system activity. '
                . 'Admin users see all entries; non-admin users see only their own log entries.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'severity' => [
                        'type' => 'integer',
                        'description' => 'Minimum severity level: 0=info, 1=notice, 2=warning, 3=error, 4=fatal. Default: 0 (show all)',
                        'minimum' => 0,
                        'maximum' => 4,
                        'default' => 0,
                    ],
                    'action' => [
                        'type' => 'integer',
                        'description' => 'Filter by action type: 0=login, 1=action, 2=error, 3=cache, 4=settings, 254=extension, 255=internal',
                    ],
                    'component' => [
                        'type' => 'string',
                        'description' => 'Filter by log component (e.g. "TYPO3.CMS.Core", "TYPO3.CMS.Backend", or extension namespace)',
                    ],
                    'tablename' => [
                        'type' => 'string',
                        'description' => 'Filter by affected table name (e.g. "tt_content", "pages")',
                    ],
                    'userId' => [
                        'type' => 'integer',
                        'description' => 'Filter by backend user ID (admin only)',
                    ],
                    'since' => [
                        'type' => 'string',
                        'description' => 'ISO datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS): only entries after this time',
                    ],
                    'until' => [
                        'type' => 'string',
                        'description' => 'ISO datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS): only entries before this time',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of entries (default: 50, max: 500)',
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

        $isAdmin = $backendUser->isAdmin();
        $currentUserId = is_numeric($backendUser->user['uid'] ?? null) ? (int)$backendUser->user['uid'] : 0;

        $severity = is_numeric($params['severity'] ?? null) ? (int)$params['severity'] : 0;
        $action = is_numeric($params['action'] ?? null) ? (int)$params['action'] : null;
        $component = is_string($params['component'] ?? null) ? trim($params['component']) : '';
        $tablename = is_string($params['tablename'] ?? null) ? trim($params['tablename']) : '';
        $userId = is_numeric($params['userId'] ?? null) ? (int)$params['userId'] : null;
        $since = is_string($params['since'] ?? null) ? trim($params['since']) : '';
        $until = is_string($params['until'] ?? null) ? trim($params['until']) : '';
        $limit = is_numeric($params['limit'] ?? null) ? min((int)$params['limit'], self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $offset = is_numeric($params['offset'] ?? null) ? max((int)$params['offset'], 0) : 0;

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        // Non-admins cannot filter by other users
        if ($userId !== null && !$isAdmin) {
            throw new ValidationException(['Only admin users can filter by userId']);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $qb->getRestrictions()->removeAll();

        $qb->select(
            'log.uid',
            'log.tstamp',
            'log.type',
            'log.action',
            'log.error',
            'log.details',
            'log.log_data',
            'log.tablename',
            'log.recuid',
            'log.userid',
            'log.IP',
            'log.channel',
            'log.level',
            'log.component',
            'log.message',
        )
            ->addSelectLiteral(
                $qb->quoteIdentifier('u.username') . ' AS username',
            )
            ->from('sys_log', 'log')
            ->leftJoin(
                'log',
                'be_users',
                'u',
                $qb->expr()->eq('u.uid', $qb->quoteIdentifier('log.userid')),
            );

        // Non-admins see only their own entries
        if (!$isAdmin) {
            $qb->andWhere($qb->expr()->eq('log.userid', $qb->createNamedParameter($currentUserId, Connection::PARAM_INT)));
        }

        // Filter by severity using the PSR-3 level column (TYPO3 v14)
        // level mapping: emergency=0, alert=1, critical=2, error=3, warning=4, notice=5, info=6, debug=7
        // User-facing: 0=info(6+7), 1=notice(5), 2=warning(4), 3=error(3), 4=fatal(0+1+2)
        if ($severity > 0) {
            $maxLevel = match ($severity) {
                1 => 5, // notice and above
                2 => 4, // warning and above
                3 => 3, // error and above
                4 => 2, // critical and above (fatal)
                default => 7,
            };
            $qb->andWhere($qb->expr()->lte('log.level', $qb->createNamedParameter($maxLevel, Connection::PARAM_INT)));
        }

        if ($action !== null) {
            $qb->andWhere($qb->expr()->eq('log.action', $qb->createNamedParameter($action, Connection::PARAM_INT)));
        }

        if ($component !== '') {
            $likeComponent = '%' . $qb->escapeLikeWildcards($component) . '%';
            $qb->andWhere($qb->expr()->like('log.component', $qb->createNamedParameter($likeComponent)));
        }

        if ($tablename !== '') {
            $qb->andWhere($qb->expr()->eq('log.tablename', $qb->createNamedParameter($tablename)));
        }

        if ($userId !== null && $isAdmin) {
            $qb->andWhere($qb->expr()->eq('log.userid', $qb->createNamedParameter($userId, Connection::PARAM_INT)));
        }

        if ($since !== '') {
            $ts = strtotime($since);
            if ($ts === false) {
                throw new ValidationException(['Invalid "since" date format. Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS.']);
            }
            $qb->andWhere($qb->expr()->gte('log.tstamp', $qb->createNamedParameter($ts, Connection::PARAM_INT)));
        }

        if ($until !== '') {
            $ts = strtotime($until);
            if ($ts === false) {
                throw new ValidationException(['Invalid "until" date format. Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS.']);
            }
            $qb->andWhere($qb->expr()->lte('log.tstamp', $qb->createNamedParameter($ts, Connection::PARAM_INT)));
        }

        // Count total using a separate query builder
        $countQb = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $countQb->getRestrictions()->removeAll();
        $countQb->count('log.uid')
            ->from('sys_log', 'log');

        // Re-apply the same filters for count
        if (!$isAdmin) {
            $countQb->andWhere($countQb->expr()->eq('log.userid', $countQb->createNamedParameter($currentUserId, Connection::PARAM_INT)));
        }
        if ($severity > 0) {
            $countMaxLevel = match ($severity) {
                1 => 5, 2 => 4, 3 => 3, 4 => 2, default => 7,
            };
            $countQb->andWhere($countQb->expr()->lte('log.level', $countQb->createNamedParameter($countMaxLevel, Connection::PARAM_INT)));
        }
        if ($action !== null) {
            $countQb->andWhere($countQb->expr()->eq('log.action', $countQb->createNamedParameter($action, Connection::PARAM_INT)));
        }
        if ($component !== '') {
            $countQb->andWhere($countQb->expr()->like('log.component', $countQb->createNamedParameter('%' . $countQb->escapeLikeWildcards($component) . '%')));
        }
        if ($tablename !== '') {
            $countQb->andWhere($countQb->expr()->eq('log.tablename', $countQb->createNamedParameter($tablename)));
        }
        if ($userId !== null && $isAdmin) {
            $countQb->andWhere($countQb->expr()->eq('log.userid', $countQb->createNamedParameter($userId, Connection::PARAM_INT)));
        }
        if ($since !== '') {
            $countQb->andWhere($countQb->expr()->gte('log.tstamp', $countQb->createNamedParameter((int)strtotime($since), Connection::PARAM_INT)));
        }
        if ($until !== '') {
            $countQb->andWhere($countQb->expr()->lte('log.tstamp', $countQb->createNamedParameter((int)strtotime($until), Connection::PARAM_INT)));
        }

        $totalValue = $countQb->executeQuery()->fetchOne();
        $total = is_numeric($totalValue) ? (int)$totalValue : 0;

        // Fetch results
        $qb->orderBy('log.tstamp', 'DESC')
            ->addOrderBy('log.uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $entries = [];
        foreach ($rows as $row) {
            $tstamp = is_numeric($row['tstamp'] ?? null) ? (int)$row['tstamp'] : 0;
            $message = $this->resolveLogMessage($row);

            $entry = [
                'uid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'timestamp' => $tstamp > 0 ? date('c', $tstamp) : '',
                'level' => is_scalar($row['level'] ?? null) ? $this->mapPsr3Level((int)$row['level']) : 'unknown',
                'type' => is_numeric($row['type'] ?? null) ? (int)$row['type'] : 0,
                'action' => is_numeric($row['action'] ?? null) ? (int)$row['action'] : 0,
                'component' => is_scalar($row['component'] ?? null) ? (string)$row['component'] : '',
                'message' => $message,
            ];

            $tableName = is_scalar($row['tablename'] ?? null) ? (string)$row['tablename'] : '';
            $recuid = is_numeric($row['recuid'] ?? null) ? (int)$row['recuid'] : 0;
            if ($tableName !== '') {
                $entry['tablename'] = $tableName;
            }
            if ($recuid > 0) {
                $entry['recordUid'] = $recuid;
            }

            $entry['user'] = [
                'uid' => is_numeric($row['userid'] ?? null) ? (int)$row['userid'] : 0,
                'username' => is_scalar($row['username'] ?? null) ? (string)$row['username'] : '',
            ];

            $ip = is_scalar($row['IP'] ?? null) ? (string)$row['IP'] : '';
            if ($ip !== '') {
                $entry['ip'] = $ip;
            }

            $entries[] = $entry;
        }

        $returned = count($entries);
        $hasMore = ($offset + $returned) < $total;

        $result = [
            'entries' => $entries,
            'total' => $total,
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

    /**
     * Resolve the human-readable log message from sys_log row data.
     *
     * @param array<string, mixed> $row
     */
    private function resolveLogMessage(array $row): string
    {
        // Prefer PSR-3 message field (TYPO3 v14)
        $message = is_scalar($row['message'] ?? null) ? (string)$row['message'] : '';
        if ($message !== '') {
            return $message;
        }

        // Fall back to legacy details + log_data
        $details = is_scalar($row['details'] ?? null) ? (string)$row['details'] : '';
        if ($details === '') {
            return '';
        }

        $logData = $row['log_data'] ?? null;
        if (is_string($logData) && $logData !== '') {
            $decoded = json_decode($logData, true);
            if (is_array($decoded) && $decoded !== []) {
                // Ensure all values are scalar for vsprintf
                $scalarValues = array_map(
                    static fn(mixed $v): string => is_scalar($v) ? (string)$v : '',
                    array_values($decoded),
                );
                try {
                    return vsprintf($details, $scalarValues);
                } catch (\ValueError) {
                    // vsprintf failed, return raw details
                }
            }
        }

        return $details;
    }

    private function mapPsr3Level(int $level): string
    {
        return match ($level) {
            0 => 'emergency',
            1 => 'alert',
            2 => 'critical',
            3 => 'error',
            4 => 'warning',
            5 => 'notice',
            6 => 'info',
            7 => 'debug',
            default => 'unknown',
        };
    }
}
