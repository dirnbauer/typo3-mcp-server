<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\Record\RecordFieldReadConverter;
use Hn\McpServer\Service\Record\RecordReadQueryService;
use Hn\McpServer\Service\Record\RecordRelationReadService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Tool for reading records from TYPO3 tables
 *
 * @phpstan-type RecordRow array<string, mixed>
 * @phpstan-type RecordRows list<RecordRow>
 */
final class ReadTableTool extends AbstractRecordTool
{
    private const ALLOWED_OPERATORS = [
        'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
        'like', 'notLike',
        'in', 'notIn',
        'isNull', 'isNotNull',
    ];
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        protected readonly LanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
        private readonly RecordReadQueryService $readQueryService,
        private readonly RecordRelationReadService $relationReadService,
        private readonly RecordFieldReadConverter $fieldReadConverter,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1748000001);
        }
        return $backendUser;
    }

    /**
     * IDOR defense: refuse to query records on a page outside the BE user's
     * webmount. Admins pass through. The error message keeps the same tone
     * as WriteTableTool::validatePageAccess().
     */
    private function ensurePageAccess(int $pid): void
    {
        $beUser = $this->getBackendUser();
        if ($beUser->isAdmin()) {
            return;
        }
        // BackendUserAuthentication::isInWebMount() returns int|null (the
        // matched mount UID, or null when not in any mount). Cast to bool
        // explicitly for phpstan-strict-rules.
        if (((int)($beUser->isInWebMount($pid) ?? 0)) <= 0) {
            throw new ValidationException([sprintf(
                'Permission denied: You do not have access to page %d. Your account needs database mount point (DB Mount) ' .
                'access to this page or its parent pages. Contact your administrator.',
                $pid,
            )]);
        }
    }

    /**
     * Drop rows whose pid is outside the BE user's webmount. Admins keep
     * everything. Used when callers do uid-only lookups so we don't bypass
     * the page-level IDOR gate.
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function filterRecordsByWebMount(array $records): array
    {
        $beUser = $this->getBackendUser();
        if ($beUser->isAdmin()) {
            return array_values($records);
        }
        return array_values(array_filter(
            $records,
            static function (array $row) use ($beUser): bool {
                if (!is_numeric($row['pid'] ?? null)) {
                    return false;
                }
                $pid = (int)$row['pid'];
                if ($pid < 0) {
                    return false;
                }
                return ((int)($beUser->isInWebMount($pid) ?? 0)) > 0;
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        // Check if multiple languages are available
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        $hasMultipleLanguages = count($availableLanguages) > 1;

        // Get all accessible tables for enum
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        $tableNames = array_keys($accessibleTables);
        sort($tableNames); // Sort alphabetically for better readability

        // Build the base properties
        $properties = [
            'table' => [
                'type' => 'string',
                'description' => 'The table name to read records from',
                'enum' => $tableNames,
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'Filter by page ID (recommended for content tables). Omit for root-level tables like sys_file that store records at pid=0.',
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Filter by record UID (use pid filter instead to read multiple records of a page)',
            ],
            'filters' => [
                'type' => 'array',
                'description' => 'Filter conditions applied with AND. Each filter has field, operator, and optionally value.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => [
                            'type' => 'string',
                            'description' => 'Column name to filter on',
                        ],
                        'operator' => [
                            'type' => 'string',
                            'description' => 'Comparison operator',
                            'enum' => self::ALLOWED_OPERATORS,
                        ],
                        'value' => [
                            'description' => 'Value to compare against (not needed for isNull/isNotNull). Use array for in/notIn.',
                        ],
                    ],
                    'required' => ['field', 'operator'],
                ],
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of records to return (default: 20)',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Offset for pagination',
            ],
            'fields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Optional list of field names to include in the result. Only uid is always included. When omitted, all type-relevant fields are returned. Use GetTableSchema to discover available fields.',
            ],
        ];

        // Only add language parameters if multiple languages are configured
        if ($hasMultipleLanguages) {
            $properties['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to filter records by (e.g., "en", "de", "fr"). Without this parameter, records from ALL languages are returned mixed together, similar to TYPO3\'s list module. For UID lookups, consider omitting this parameter to ensure the record can be found regardless of language.',
                'enum' => $availableLanguages,
            ];
            $properties['includeTranslationSource'] = [
                'type' => 'boolean',
                'description' => 'Include translation source information for translated records (default: false)',
            ];
        }

        return [
            'description' => 'Read records from TYPO3 tables with filtering, pagination, and relation embedding. ' .
                'Returns records from ALL languages mixed together by default (like TYPO3\'s list module). Use the language parameter to filter. ' .
                'Hidden records are always included (like the TYPO3 backend); only deleted records are excluded. ' .
                'INLINE RELATIONS: Embedded inline fields (hideTable/passthrough) return full child record data arrays. Independent inline fields return only UIDs. ' .
                'MM RELATIONS: Basic support — does not resolve foreign_table_where conditions or complex TYPO3 relation scenarios. ' .
                'WORKSPACE: Returns live UIDs for workspace-overlaid records (transparent for subsequent WriteTable calls). ' .
                'OUTPUT: {table, tableLabel, records[], total, limit, offset, hasMore}. Max limit: 1000. ' .
                'For page content, use pid filter instead of individual record lookups.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Validate table access
        $table = isset($params['table']) && is_string($params['table']) ? $params['table'] : '';
        if (empty($table)) {
            throw new ValidationException(['Table name is required']);
        }

        // Reject the legacy `where` payload with a clear error instead of silently dropping it.
        if (isset($params['where'])) {
            throw new ValidationException([
                'The legacy "where" parameter is not supported. Use "filters" instead: an array of '
                . '{field, operator, value} objects combined with AND. Supported operators: '
                . implode(', ', self::ALLOWED_OPERATORS) . '.',
            ]);
        }

        $this->ensureTableAccess($table, 'read');
        $tableName = $table;

        // Execute main logic
        // Extract and validate parameters
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $uidParam = $params['uid'] ?? null;
        $uid = is_array($uidParam)
            ? array_values(array_map(intval(...), array_filter($uidParam, is_numeric(...))))
            : (is_numeric($uidParam) ? (int)$uidParam : null);
        $filtersParam = $params['filters'] ?? [];
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $language = $params['language'] ?? null;
        $includeTranslationSource = $params['includeTranslationSource'] ?? false;
        $requestedFields = $this->readQueryService->normalizeFieldNames($tableName, $params['fields'] ?? []);

        // Normalize system field filters so callers can use friendly values:
        //   - sys_language_uid accepts ISO codes ("de") in addition to UIDs
        //   - hidden accepts booleans
        $filters = $this->readQueryService->normalizeSystemFieldFilters($tableName, $filtersParam, $pid);

        // Ensure translation parent field is included when translation source is requested
        if ($includeTranslationSource && !empty($requestedFields)) {
            $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
            if ($translationParentField && !in_array($translationParentField, $requestedFields)) {
                $requestedFields[] = $translationParentField;
            }
        }

        // Validate parameters
        if ($limit < 1 || $limit > 1000) {
            throw new ValidationException(['Limit must be between 1 and 1000']);
        }
        if ($offset < 0) {
            throw new ValidationException(['Offset must be non-negative']);
        }

        // A01 / IDOR defense: when the caller targets a specific page, refuse
        // unless it is in the BE user's webmount. This is the same gate
        // WriteTableTool::validatePageAccess applies to writes.
        // For root-level/non-pid tables the check is skipped (pid is null or 0).
        if ($pid !== null && $pid > 0) {
            $this->ensurePageAccess($pid);
        }

        // Convert language ISO code to UID if provided
        $languageUid = null;
        if ($language !== null) {
            $languageUid = $this->languageService->getUidFromIsoCode($language);
            if ($languageUid === null) {
                throw new ValidationException(["Unknown language code: {$language}"]);
            }
        }

        // Get records from the table
        $result = $this->readQueryService->getRecords(
            $table,
            $pid,
            $uid,
            $filters,
            $limit,
            $offset,
            $languageUid,
            $requestedFields
        );

        // A01 / IDOR defense: when the caller looked up by uid (no pid filter),
        // post-filter rows whose pid is outside the user's webmounts. Admins
        // pass through unchanged. Root-level rows (pid=0) are returned only
        // for tables that store everything at pid=0 (sys_file, ...) — those
        // are read-only via TCA / additionalReadOnlyTables anyway.
        if ($uid !== null && $pid === null) {
            $result['records'] = $this->filterRecordsByWebMount($result['records']);
            $result['total'] = count($result['records']);
            $result['hasMore'] = false;
        }

        // Include related records
        $result = $this->relationReadService->includeRelations($result, $table, $requestedFields);

        // Include translation metadata if requested
        if ($includeTranslationSource && $languageUid !== null && $languageUid > 0) {
            $result['translationSource'] = $this->getTranslationSourceData($result['records'], $table);
        }

        // Return the result as JSON
        return $this->createJsonResult($result);
    }

    /**
     * Get translation source data for records
     */
    protected function getTranslationSourceData(array $records, string $table): array
    {
        $translationData = [];

        // Get translation parent field name
        $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
        if (empty($translationParentField)) {
            return [];
        }

        // Collect parent UIDs
        $parentUids = [];
        foreach ($records as $record) {
            if (!empty($record[$translationParentField])) {
                $parentUids[] = (int)$record[$translationParentField];
            }
        }

        if (empty($parentUids)) {
            return [];
        }

        // Load parent records
        $parentRecords = $this->loadParentRecords($table, array_unique($parentUids));

        // Build translation metadata
        foreach ($records as $record) {
            if (!empty($record[$translationParentField])) {
                $parentUid = (int)$record[$translationParentField];
                $recordUid = (int)$record['uid'];

                if (isset($parentRecords[$parentUid])) {
                    $parentRecord = $parentRecords[$parentUid];

                    // Get excluded and synchronized fields
                    $excludedFields = $this->tableAccessService->getExcludedFieldsInTranslation($table);
                    $inheritedValues = [];

                    // Collect inherited field values
                    foreach ($excludedFields as $field) {
                        if (isset($parentRecord[$field])) {
                            $inheritedValues[$field] = $this->fieldReadConverter->convertFieldValue($table, $field, $parentRecord[$field]);
                        }
                    }

                    $translationData[$recordUid] = [
                        'sourceUid' => $parentUid,
                        'sourceLanguage' => $this->languageService->getIsoCodeFromUid(0) ?? 'default',
                        'inheritedFields' => $inheritedValues,
                    ];
                }
            }
        }

        return $translationData;
    }

    /**
     * Load parent records for translations
     */
    protected function loadParentRecords(string $table, array $parentUids): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->getBackendUser()->workspace ?? 0))
            ->add(new WorkspaceDeletePlaceholderRestriction($this->getBackendUser()->workspace ?? 0));

        $records = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->readQueryService->applyWorkspaceOverlay($table, $records);

        // Process and index by UID
        $indexedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->fieldReadConverter->processRecord($record, $table);
            $indexedRecords[$processedRecord['uid']] = $processedRecord;
        }

        return $indexedRecords;
    }

}
