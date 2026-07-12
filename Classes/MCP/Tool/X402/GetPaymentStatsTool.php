<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\X402;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\X402\X402ContentAccessService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * MCP tool for querying x402 payment statistics.
 *
 * Returns revenue data, transaction counts, and top-performing
 * paid content pages. Works with the tx_x402_payment_log table
 * from the typo3-x402-paywall extension.
 */
#[AdminOnly]
final class GetPaymentStatsTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
        private readonly X402ContentAccessService $contentAccess,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Get x402 payment statistics. Returns revenue, transaction counts, and top content. '
                . 'Requires the typo3-x402-paywall extension with payment logging enabled.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'string',
                        'description' => 'Time period for stats',
                        'enum' => ['today', '7days', '30days', 'all'],
                        'default' => '30days',
                    ],
                    'groupBy' => [
                        'type' => 'string',
                        'description' => 'Group results by dimension',
                        'enum' => ['page', 'day', 'network'],
                        'default' => 'page',
                    ],
                ],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $periodRaw = $params['period'] ?? '30days';
        $period = is_string($periodRaw) ? $periodRaw : '30days';
        $groupByRaw = $params['groupBy'] ?? 'page';
        $groupBy = is_string($groupByRaw) ? $groupByRaw : 'page';

        // Check if payment log table exists
        if (!$this->databaseTableExists('tx_x402_payment_log')) {
            if ($this->columnExists('pages', 'tx_x402_paywall_enabled')) {
                $this->ensureTableAccess('pages', 'read');
            }
            return $this->returnConfigStatus();
        }

        // Payment logs are not workspace-versioned, so use the dedicated
        // read-only access check after establishing the page read context.
        $this->ensureTableAccess('pages', 'read');
        $this->tableAccessService->validateReadTableAccess('tx_x402_payment_log');

        $sinceValue = match ($period) {
            'today' => strtotime('today'),
            '7days' => strtotime('-7 days'),
            '30days' => strtotime('-30 days'),
            default => 0,
        };
        $since = is_int($sinceValue) ? $sinceValue : 0;

        $stats = $this->getPaymentStats($since, $groupBy);

        return new CallToolResult([
            new TextContent(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPaymentStats(int $since, string $groupBy): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_x402_payment_log');

        // Total revenue and count
        $queryBuilder
            ->addSelectLiteral('COUNT(*) as transaction_count')
            ->addSelectLiteral('SUM(CAST(amount as DECIMAL(20,6))) as total_revenue')
            ->from('tx_x402_payment_log');

        if ($since > 0) {
            $queryBuilder->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, ParameterType::INTEGER))
            );
        }

        $totals = $queryBuilder->executeQuery()->fetchAssociative();
        if (!is_array($totals)) {
            $totals = [];
        }

        // Grouped data
        $groupedData = $this->getGroupedData($since, $groupBy);

        // Gated page count
        $gatedCount = $this->getGatedPageCount();

        $transactionCountRaw = $totals['transaction_count'] ?? 0;
        $totalTransactions = is_numeric($transactionCountRaw) ? (int)$transactionCountRaw : 0;
        $revenueRaw = $totals['total_revenue'] ?? 0;
        $totalRevenue = is_numeric($revenueRaw) ? (float)$revenueRaw : 0.0;

        return [
            'period' => $since > 0 ? date('Y-m-d', $since) . ' to now' : 'all time',
            'summary' => [
                'total_transactions' => $totalTransactions,
                'total_revenue_usdc' => round($totalRevenue, 6),
                'gated_pages' => $gatedCount,
            ],
            'grouped_by' => $groupBy,
            'data' => $groupedData,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getGroupedData(int $since, string $groupBy): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_x402_payment_log');

        $selectFields = match ($groupBy) {
            'page' => [
                'page_uid',
                'COUNT(*) as transactions',
                'SUM(CAST(amount as DECIMAL(20,6))) as revenue',
            ],
            'day' => [
                'DATE(FROM_UNIXTIME(crdate)) as date',
                'COUNT(*) as transactions',
                'SUM(CAST(amount as DECIMAL(20,6))) as revenue',
            ],
            'network' => [
                'network',
                'COUNT(*) as transactions',
                'SUM(CAST(amount as DECIMAL(20,6))) as revenue',
            ],
            default => ['COUNT(*) as transactions'],
        };

        foreach ($selectFields as $field) {
            $queryBuilder->addSelectLiteral($field);
        }

        $queryBuilder->from('tx_x402_payment_log');

        if ($since > 0) {
            $queryBuilder->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, ParameterType::INTEGER))
            );
        }

        $groupField = match ($groupBy) {
            'page' => 'page_uid',
            'day' => 'DATE(FROM_UNIXTIME(crdate))',
            'network' => 'network',
            default => null,
        };

        if ($groupField !== null) {
            $queryBuilder->addGroupBy($groupField);
            $queryBuilder->orderBy('revenue', 'DESC');
        }

        $queryBuilder->setMaxResults(50);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private function getGatedPageCount(): int
    {
        return count($this->contentAccess->getGatedPages());
    }

    private function returnConfigStatus(): CallToolResult
    {
        // No payment log table → check if x402 paywall fields exist on pages
        $hasPaywallFields = $this->columnExists('pages', 'tx_x402_paywall_enabled');

        $status = [
            'status' => 'configuration_info',
            'x402_paywall_extension' => $hasPaywallFields ? 'installed (fields detected)' : 'not installed',
            'payment_log_table' => 'not found',
            'gated_pages' => $hasPaywallFields ? $this->getGatedPageCount() : 0,
            'message' => $hasPaywallFields
                ? 'x402 paywall fields exist on pages but payment logging table is missing. Install the payment logging module or upgrade to v1.1.'
                : 'Install webconsulting/typo3-x402-paywall to enable x402 content monetization.',
        ];

        return new CallToolResult([
            new TextContent(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ]);
    }

    private function databaseTableExists(string $table): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            return $connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Exception) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($table === '') {
            return false;
        }
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            foreach ($connection->createSchemaManager()->introspectTableColumnsByUnquotedName($table) as $tableColumn) {
                if ($tableColumn->getObjectName()->getIdentifier()->getValue() === $column) {
                    return true;
                }
            }
            return false;
        } catch (\Exception) {
            return false;
        }
    }
}
