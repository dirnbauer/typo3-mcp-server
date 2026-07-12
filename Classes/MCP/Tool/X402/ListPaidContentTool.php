<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\X402;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\X402\X402ContentAccessService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Discovers gated pages visible to the current TYPO3 backend user.
 */
final class ListPaidContentTool extends AbstractRecordTool
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
            'description' => 'List x402-gated pages visible in the current TYPO3 workspace, language, page permissions, and database mounts.',
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
                        'description' => 'Filter by an accessible parent page UID.',
                        'minimum' => 1,
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Optional TYPO3 site language ISO code.',
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
        $limitRaw = $params['limit'] ?? 50;
        $limit = min(200, max(1, is_numeric($limitRaw) ? (int)$limitRaw : 50));
        $offsetRaw = $params['offset'] ?? 0;
        $offset = max(0, is_numeric($offsetRaw) ? (int)$offsetRaw : 0);
        $parentPageUid = null;
        if (array_key_exists('parentPageUid', $params)) {
            $parentRaw = $params['parentPageUid'];
            $parentPageUid = is_numeric($parentRaw) ? (int)$parentRaw : 0;
            if ($parentPageUid <= 0) {
                return $this->createErrorResult('parentPageUid must be a positive page UID.');
            }
        }
        $language = isset($params['language']) && is_string($params['language'])
            ? trim($params['language'])
            : null;

        if (!$this->hasPaywallColumns()) {
            return $this->returnConfigStatus($limit, $offset, $parentPageUid);
        }

        // Establish the requested read-workspace and enforce table permission.
        $this->ensureTableAccess('pages', 'read');
        $allPages = $this->contentAccess->getGatedPages($parentPageUid, $language);
        $total = count($allPages);
        $pages = array_slice($allPages, $offset, $limit);

        $resultPages = [];
        foreach ($pages as $page) {
            $pageData = $this->contentAccess->projectPage($page);
            $price = is_scalar($page['tx_x402_paywall_price'] ?? null)
                ? (string)$page['tx_x402_paywall_price']
                : '';
            $description = is_scalar($page['tx_x402_paywall_description'] ?? null)
                ? (string)$page['tx_x402_paywall_description']
                : '';
            $resultPages[] = [
                ...$pageData,
                'x402' => [
                    'price' => $price !== '' ? $price : '0.01',
                    'currency' => 'USDC',
                    'description' => $description !== '' ? $description : ($pageData['title'] ?? ''),
                ],
            ];
        }

        return $this->jsonResult([
            'pages' => $resultPages,
            'total' => $total,
            'count' => count($resultPages),
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + count($resultPages)) < $total,
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

        return $this->jsonResult($status);
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

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResult(array $data): CallToolResult
    {
        return new CallToolResult([
            new TextContent(json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            )),
        ]);
    }
}
