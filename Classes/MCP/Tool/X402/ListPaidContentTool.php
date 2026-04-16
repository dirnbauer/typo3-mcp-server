<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\X402;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP tool for discovering x402-gated content.
 *
 * AI agents can use this to find which pages have paid content,
 * their prices, and descriptions — before deciding to pay.
 */
final class ListPaidContentTool extends AbstractTool
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getName(): string
    {
        return 'ListPaidContent';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'List all pages that require x402 payment for access. '
                . 'Returns page UIDs, titles, prices, and descriptions. '
                . 'Use this to discover paid content before requesting it with GetPaidContent.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of pages to return (default: 50)',
                        'default' => 50,
                        'minimum' => 1,
                        'maximum' => 200,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Offset for pagination',
                        'default' => 0,
                        'minimum' => 0,
                    ],
                    'parentPageUid' => [
                        'type' => 'integer',
                        'description' => 'Filter by parent page UID (optional)',
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
        $limitRaw = $params['limit'] ?? 50;
        $limit = min(200, max(1, is_numeric($limitRaw) ? (int)$limitRaw : 50));
        $offsetRaw = $params['offset'] ?? 0;
        $offset = max(0, is_numeric($offsetRaw) ? (int)$offsetRaw : 0);
        $parentPageUid = null;
        if (isset($params['parentPageUid'])) {
            $parentRaw = $params['parentPageUid'];
            $parentPageUid = is_numeric($parentRaw) ? (int)$parentRaw : null;
        }

        if (!$this->hasPaywallColumns()) {
            return $this->returnConfigStatus($limit, $offset, $parentPageUid);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->select(
                'uid',
                'pid',
                'title',
                'subtitle',
                'abstract',
                'tx_x402_paywall_enabled',
                'tx_x402_paywall_price',
                'tx_x402_paywall_description'
            )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_x402_paywall_enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('sorting', 'ASC');

        if ($parentPageUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentPageUid, ParameterType::INTEGER))
            );
        }

        $pages = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Count total
        $countBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $countBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $countBuilder->count('uid')->from('pages')
            ->where($countBuilder->expr()->eq('tx_x402_paywall_enabled', $countBuilder->createNamedParameter(1, ParameterType::INTEGER)));
        if ($parentPageUid !== null) {
            $countBuilder->andWhere(
                $countBuilder->expr()->eq('pid', $countBuilder->createNamedParameter($parentPageUid, ParameterType::INTEGER))
            );
        }
        $totalRaw = $countBuilder->executeQuery()->fetchOne();
        $total = is_numeric($totalRaw) ? (int)$totalRaw : 0;

        $result = [
            'pages' => array_map(fn(array $page) => [
                'uid' => $page['uid'],
                'pid' => $page['pid'],
                'title' => $page['title'],
                'subtitle' => $page['subtitle'] ?? '',
                'abstract' => $page['abstract'] ?? '',
                'x402' => [
                    'price' => $page['tx_x402_paywall_price'] ?: '0.01',
                    'currency' => 'USDC',
                    'description' => $page['tx_x402_paywall_description'] ?: $page['title'],
                ],
            ], $pages),
            'total' => $total,
            'count' => count($pages),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + count($pages)) < $total,
        ];

        return new CallToolResult([
            new TextContent(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ]);
    }

    private function hasPaywallColumns(): bool
    {
        return $this->columnExists('pages', 'tx_x402_paywall_enabled')
            && $this->columnExists('pages', 'tx_x402_paywall_price')
            && $this->columnExists('pages', 'tx_x402_paywall_description');
    }

    private function returnConfigStatus(int $limit, int $offset, ?int $parentPageUid): CallToolResult
    {
        $status = [
            'status' => 'configuration_info',
            'x402_paywall_extension' => 'not installed',
            'message' => 'Install webconsulting/typo3-x402-paywall to list paid content.',
            'pages' => [],
            'total' => 0,
            'count' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => false,
        ];

        if ($parentPageUid !== null) {
            $status['parentPageUid'] = $parentPageUid;
        }

        return new CallToolResult([
            new TextContent(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
        ]);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $columns = $connection->createSchemaManager()->listTableColumns($table);
            return isset($columns[$column]);
        } catch (\Exception) {
            return false;
        }
    }
}
