<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\X402;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP tool for querying x402 payment statistics.
 *
 * Returns revenue data, transaction counts, and top-performing
 * paid content pages. Works with the tx_x402_payment_log table
 * from the typo3-x402-paywall extension.
 */
final class GetPaymentStatsTool extends AbstractTool
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getName(): string
    {
        return 'GetPaymentStats';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
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
        $period = (string)($params['period'] ?? '30days');
        $groupBy = (string)($params['groupBy'] ?? 'page');

        // Check if payment log table exists
        if (!$this->tableExists('tx_x402_payment_log')) {
            return $this->returnConfigStatus();
        }

        $since = match ($period) {
            'today' => strtotime('today'),
            '7days' => strtotime('-7 days'),
            '30days' => strtotime('-30 days'),
            default => 0,
        };

        $stats = $this->getPaymentStats($since ?: 0, $groupBy);

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
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, \Doctrine\DBAL\ParameterType::INTEGER))
            );
        }

        $totals = $queryBuilder->executeQuery()->fetchAssociative();

        // Grouped data
        $groupedData = $this->getGroupedData($since, $groupBy);

        // Gated page count
        $gatedCount = $this->getGatedPageCount();

        return [
            'period' => $since > 0 ? date('Y-m-d', $since) . ' to now' : 'all time',
            'summary' => [
                'total_transactions' => (int)($totals['transaction_count'] ?? 0),
                'total_revenue_usdc' => round((float)($totals['total_revenue'] ?? 0), 6),
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
                "DATE(FROM_UNIXTIME(crdate)) as date",
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
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, \Doctrine\DBAL\ParameterType::INTEGER))
            );
        }

        $groupField = match ($groupBy) {
            'page' => 'page_uid',
            'day' => "DATE(FROM_UNIXTIME(crdate))",
            'network' => 'network',
            default => null,
        };

        if ($groupField) {
            $queryBuilder->addGroupBy($groupField);
            $queryBuilder->orderBy('revenue', 'DESC');
        }

        $queryBuilder->setMaxResults(50);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private function getGatedPageCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('tx_x402_paywall_enabled', $queryBuilder->createNamedParameter(1, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
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

    private function tableExists(string $table): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            return $connection->getSchemaManager()->tablesExist([$table]);
        } catch (\Exception) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $columns = $connection->getSchemaManager()->listTableColumns($table);
            return isset($columns[$column]);
        } catch (\Exception) {
            return false;
        }
    }
}
