<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Manage TYPO3 redirect records (sys_redirect): list, create, and delete.
 */
final class ManageRedirectsTool extends AbstractRecordTool
{
    private const TABLE = 'sys_redirect';
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307];
    private const WRITE_UNSUPPORTED_MESSAGE = 'ManageRedirects cannot modify redirects on this TYPO3 instance because '
        . 'sys_redirect is not workspace-capable and local live writes are disabled. '
        . 'Use the TYPO3 backend or another admin workflow for redirect changes, or run in trusted local mode '
        . '(DDEV, TYPO3 Development context, or localUnsafeMode=on with strictSandbox off).';

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
        private readonly LocalModeService $localMode,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Inspect TYPO3 URL redirects (sys_redirect). '
                . 'Supports listing existing redirects with filters. Create/delete is available when sys_redirect is workspace-capable '
                . 'or when trusted local mode permits live writes (DDEV, TYPO3 Development context, or localUnsafeMode=on with strictSandbox off). '
                . 'Otherwise write actions return a workspace-safety limitation message. '
                . 'Redirects map a source host + path to a target URL or page with a configurable HTTP status code.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['list', 'create', 'delete'],
                        'description' => 'Action to perform: "list" to query redirects, "create" to add a new redirect, "delete" to remove one by UID.',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => '(delete) UID of the redirect record to delete.',
                    ],
                    'source_host' => [
                        'type' => 'string',
                        'description' => '(list, create) The source hostname. For list: filter by host. For create: the host to match (use "*" for any host).',
                    ],
                    'source_path' => [
                        'type' => 'string',
                        'description' => '(list, create) The source path. Must start with "/". For list: filters by LIKE pattern (supports % wildcards). For create: the exact path to match.',
                    ],
                    'target' => [
                        'type' => 'string',
                        'description' => '(list, create) The redirect target. For list: filter by target (LIKE). For create: target URL or TYPO3 page reference (e.g. "t3://page?uid=42").',
                    ],
                    'target_statuscode' => [
                        'type' => 'integer',
                        'description' => '(create) HTTP status code: 301 (permanent, default), 302, 303, or 307.',
                        'default' => 301,
                    ],
                    'force_https' => [
                        'type' => 'boolean',
                        'description' => '(create) Whether the redirect should enforce HTTPS on the target. Default: false.',
                        'default' => false,
                    ],
                    'respect_query_parameters' => [
                        'type' => 'boolean',
                        'description' => '(create) Whether query parameters in the source URL should be considered for matching. Default: false.',
                        'default' => false,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => '(list) Maximum number of results (default: 50, max: 200).',
                        'default' => self::DEFAULT_LIMIT,
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => '(list) Pagination offset (default: 0).',
                        'default' => 0,
                        'minimum' => 0,
                    ],
                ],
                'required' => ['action'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $action = is_string($params['action'] ?? null) ? $params['action'] : '';

        if (!$this->redirectsAreAvailable()) {
            return $this->createJsonResult([
                'status' => 'configuration_info',
                'tool' => 'ManageRedirects',
                'redirects_extension' => 'not available',
                'message' => 'The TYPO3 redirects surface is not available in this instance. '
                    . 'Install and enable the redirects system extension so sys_redirect is registered.',
            ]);
        }

        return match ($action) {
            'list' => $this->handleList($params),
            'create' => $this->redirectWritesAreSupported()
                ? $this->handleCreate($params)
                : $this->createErrorResult(self::WRITE_UNSUPPORTED_MESSAGE),
            'delete' => $this->redirectWritesAreSupported()
                ? $this->handleDelete($params)
                : $this->createErrorResult(self::WRITE_UNSUPPORTED_MESSAGE),
            default => throw new ValidationException(['Invalid action "' . $action . '". Use "list", "create", or "delete".']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleList(array $params): CallToolResult
    {
        $this->tableAccessService->validateReadTableAccess(self::TABLE);

        $sourceHost = is_string($params['source_host'] ?? null) ? trim($params['source_host']) : '';
        $sourcePath = is_string($params['source_path'] ?? null) ? trim($params['source_path']) : '';
        $target = is_string($params['target'] ?? null) ? trim($params['target']) : '';
        $limit = is_numeric($params['limit'] ?? null) ? min((int)$params['limit'], self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $offset = is_numeric($params['offset'] ?? null) ? max((int)$params['offset'], 0) : 0;

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $qb->select('uid', 'source_host', 'source_path', 'target', 'target_statuscode', 'force_https', 'respect_query_parameters', 'is_regexp', 'creation_type', 'createdon', 'updatedon', 'disabled')
            ->from(self::TABLE);

        if ($sourceHost !== '') {
            $qb->andWhere($qb->expr()->eq('source_host', $qb->createNamedParameter($sourceHost)));
        }

        if ($sourcePath !== '') {
            $likePattern = '%' . $qb->escapeLikeWildcards($sourcePath) . '%';
            $qb->andWhere($qb->expr()->like('source_path', $qb->createNamedParameter($likePattern)));
        }

        if ($target !== '') {
            $likePattern = '%' . $qb->escapeLikeWildcards($target) . '%';
            $qb->andWhere($qb->expr()->like('target', $qb->createNamedParameter($likePattern)));
        }

        // Count total
        $countQb = clone $qb;
        $countQb->resetOrderBy();
        $countQb->selectLiteral('COUNT(*)');
        // Re-create count query to avoid issues with cloned select
        $countQb2 = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $countQb2->count('uid')->from(self::TABLE);

        if ($sourceHost !== '') {
            $countQb2->andWhere($countQb2->expr()->eq('source_host', $countQb2->createNamedParameter($sourceHost)));
        }
        if ($sourcePath !== '') {
            $countQb2->andWhere($countQb2->expr()->like('source_path', $countQb2->createNamedParameter('%' . $countQb2->escapeLikeWildcards($sourcePath) . '%')));
        }
        if ($target !== '') {
            $countQb2->andWhere($countQb2->expr()->like('target', $countQb2->createNamedParameter('%' . $countQb2->escapeLikeWildcards($target) . '%')));
        }

        $totalValue = $countQb2->executeQuery()->fetchOne();
        $total = is_numeric($totalValue) ? (int)$totalValue : 0;

        $qb->orderBy('createdon', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $redirects = [];
        foreach ($rows as $row) {
            $redirects[] = $this->formatRedirectRow($row);
        }

        $returned = count($redirects);
        $hasMore = ($offset + $returned) < $total;

        $result = [
            'redirects' => $redirects,
            'total' => $total,
            'returned' => $returned,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $hasMore,
        ];

        if ($hasMore) {
            $result['nextOffset'] = $offset + $returned;
        }

        return $this->createJsonResult($result);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleCreate(array $params): CallToolResult
    {
        $this->ensureTableAccess(self::TABLE, 'write');

        $sourceHost = is_string($params['source_host'] ?? null) ? trim($params['source_host']) : '*';
        $sourcePath = is_string($params['source_path'] ?? null) ? trim($params['source_path']) : '';
        $target = is_string($params['target'] ?? null) ? trim($params['target']) : '';
        $targetStatuscode = is_numeric($params['target_statuscode'] ?? null) ? (int)$params['target_statuscode'] : 301;
        $forceHttps = !empty($params['force_https']);
        $respectQueryParameters = !empty($params['respect_query_parameters']);

        $errors = [];

        if ($sourcePath === '') {
            $errors[] = 'source_path is required and must not be empty.';
        } elseif (!str_starts_with($sourcePath, '/')) {
            $errors[] = 'source_path must start with "/".';
        }

        if ($target === '') {
            $errors[] = 'target is required and must not be empty.';
        }

        if (!in_array($targetStatuscode, self::ALLOWED_STATUS_CODES, true)) {
            $errors[] = 'target_statuscode must be one of: ' . implode(', ', self::ALLOWED_STATUS_CODES) . '.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        $newId = 'NEW' . bin2hex(random_bytes(8));
        $dataMap = [
            self::TABLE => [
                $newId => [
                    'pid' => 0,
                    'source_host' => $sourceHost,
                    'source_path' => $sourcePath,
                    'target' => $target,
                    'target_statuscode' => $targetStatuscode,
                    'force_https' => $forceHttps ? 1 : 0,
                    'respect_query_parameters' => $respectQueryParameters ? 1 : 0,
                ],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $this->runInRedirectWriteContext($backendUser, static function () use ($dataHandler, $dataMap): void {
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
        });

        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating redirect: ' . implode(', ', $dataHandler->errorLog));
        }

        $createdUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
        if (!is_numeric($createdUid)) {
            return $this->createErrorResult('Error creating redirect: No UID returned.');
        }

        return $this->createJsonResult([
            'action' => 'create',
            'uid' => (int)$createdUid,
            'workspace_staged' => $this->redirectTableIsWorkspaceCapable(),
            'live_write' => !$this->redirectTableIsWorkspaceCapable(),
            'source_host' => $sourceHost,
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => $targetStatuscode,
            'force_https' => $forceHttps,
            'respect_query_parameters' => $respectQueryParameters,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleDelete(array $params): CallToolResult
    {
        $this->ensureTableAccess(self::TABLE, 'delete');

        $uid = is_numeric($params['uid'] ?? null) ? (int)$params['uid'] : 0;
        if ($uid < 1) {
            throw new ValidationException(['uid is required and must be a positive integer for delete action.']);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        // Verify the record exists
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $qb->select('uid', 'source_host', 'source_path', 'target')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return $this->createErrorResult('Redirect with uid=' . $uid . ' not found.');
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $commandMap = [self::TABLE => [$uid => ['delete' => 1]]];
        $this->runInRedirectWriteContext($backendUser, static function () use ($dataHandler, $commandMap): void {
            $dataHandler->start([], $commandMap);
            $dataHandler->process_cmdmap();
        });

        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error deleting redirect: ' . implode(', ', $dataHandler->errorLog));
        }

        return $this->createJsonResult([
            'action' => 'delete',
            'uid' => $uid,
            'workspace_staged' => $this->redirectTableIsWorkspaceCapable(),
            'live_write' => !$this->redirectTableIsWorkspaceCapable(),
            'source_host' => is_scalar($row['source_host'] ?? null) ? (string)$row['source_host'] : '',
            'source_path' => is_scalar($row['source_path'] ?? null) ? (string)$row['source_path'] : '',
            'target' => is_scalar($row['target'] ?? null) ? (string)$row['target'] : '',
        ]);
    }

    /**
     * Format a sys_redirect database row for JSON output.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRedirectRow(array $row): array
    {
        $createdOn = is_numeric($row['createdon'] ?? null) ? (int)$row['createdon'] : 0;
        $updatedOn = is_numeric($row['updatedon'] ?? null) ? (int)$row['updatedon'] : 0;

        return [
            'uid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
            'source_host' => is_scalar($row['source_host'] ?? null) ? (string)$row['source_host'] : '',
            'source_path' => is_scalar($row['source_path'] ?? null) ? (string)$row['source_path'] : '',
            'target' => is_scalar($row['target'] ?? null) ? (string)$row['target'] : '',
            'target_statuscode' => is_numeric($row['target_statuscode'] ?? null) ? (int)$row['target_statuscode'] : 301,
            'force_https' => !empty($row['force_https']),
            'respect_query_parameters' => !empty($row['respect_query_parameters']),
            'is_regexp' => !empty($row['is_regexp']),
            'disabled' => !empty($row['disabled']),
            'createdOn' => $createdOn > 0 ? date('c', $createdOn) : null,
            'updatedOn' => $updatedOn > 0 ? date('c', $updatedOn) : null,
        ];
    }

    private function redirectsAreAvailable(): bool
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($globalTca)) {
            return false;
        }

        return is_array($globalTca[self::TABLE] ?? null);
    }

    private function redirectWritesAreSupported(): bool
    {
        return $this->redirectTableIsWorkspaceCapable() || $this->localMode->allowsLiveWrites();
    }

    private function runInRedirectWriteContext(BackendUserAuthentication $backendUser, \Closure $operation): void
    {
        if ($this->redirectTableIsWorkspaceCapable()) {
            $operation();
            return;
        }

        $originalWorkspaceId = $this->workspaceContextService->getCurrentWorkspace();
        $this->workspaceContextService->setWorkspaceContext($backendUser, 0);

        try {
            $operation();
        } finally {
            $this->workspaceContextService->setWorkspaceContext($backendUser, $originalWorkspaceId);
        }
    }

    private function redirectTableIsWorkspaceCapable(): bool
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($globalTca)) {
            return false;
        }

        $tableTca = $globalTca[self::TABLE] ?? null;
        if (!is_array($tableTca)) {
            return false;
        }

        $ctrl = $tableTca['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return false;
        }

        $workspaceCapability = $ctrl['versioningWS'] ?? false;
        return $workspaceCapability === true || $workspaceCapability === 1 || $workspaceCapability === '1';
    }
}
