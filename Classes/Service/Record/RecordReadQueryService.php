<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\TableTcaResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class RecordReadQueryService
{
    private const ALLOWED_OPERATORS = [
        'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
        'like', 'notLike',
        'in', 'notIn',
        'isNull', 'isNotNull',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private TableTcaResolver $tcaResolver,
        private RecordFieldReadConverter $fieldReadConverter,
        private EventDispatcherInterface $eventDispatcher,
        private LanguageService $languageService,
    ) {}

    public function getBackendUserForRelations(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1748000001);
        }

        return $backendUser;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $this->getBackendUserForRelations();
    }

    private function getTableCtrlArray(string $table): array
    {
        return $this->tcaResolver->getCtrl($table);
    }

    private function getTableLabel(string $table): string
    {
        if (!$this->tableExists($table)) {
            return $table;
        }

        return TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));
    }

    private function tableExists(string $table): bool
    {
        return $this->tcaResolver->hasTable($table);
    }

    private function logException(\Throwable $e, string $context): void
    {
        unset($e, $context);
    }
    public function getRecords(
        string $table,
        ?int $pid,
        int|array|null $uid,
        array $filters,
        int $limit,
        int $offset,
        ?int $languageUid = null,
        array $requestedFields = []
    ): array {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->getBackendUser()->workspace ?? 0))
            ->add(new WorkspaceDeletePlaceholderRestriction($this->getBackendUser()->workspace ?? 0));

        // Always include hidden records (like the TYPO3 backend does)

        // Select all fields
        $queryBuilder->select('*')
            ->from($table);

        // Filter by pid if specified, but skip for root-level-only tables (rootLevel=1)
        // Root-level tables like sys_file store all records at pid=0
        $rootLevel = $this->getTableCtrlArray($table)['rootLevel'] ?? 0;
        $isRootLevelOnly = ($rootLevel === 1 || $rootLevel === true);
        if ($pid !== null && $this->tableHasPidField($table) && !$isRootLevelOnly) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }

        // Filter by language if specified and table has language field
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER))
                );
            }
        }

        // Filter by uid if specified
        if ($uid !== null) {
            $uids = is_array($uid) ? $uid : [$uid];
            // For workspace transparency, we need to handle both cases:
            // 1. The UID is a workspace UID (for new records)
            // 2. The UID is a live UID (for existing records with workspace versions)

            $currentWorkspace = $this->getBackendUser()->workspace ?? 0;
            if ($currentWorkspace > 0 && !empty($this->getTableCtrlArray($table)['versioningWS'] ?? false)) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                        $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY))
                    )
                );
            } else {
                // In live workspace, just filter by UID
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY))
                );
            }
        }

        // Apply structured filters
        if (!empty($filters)) {
            $this->applyFilters($queryBuilder, $filters, $table);
        }

        // Apply default sorting from TCA
        $this->applyDefaultSorting($queryBuilder, $table);

        // Apply pagination
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);

            if ($offset > 0) {
                $queryBuilder->setFirstResult($offset);
            }
        }

        // Get total count (without pagination)
        $countQueryBuilder = $this->connectionPool
            ->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->getBackendUser()->workspace ?? 0))
            ->add(new WorkspaceDeletePlaceholderRestriction($this->getBackendUser()->workspace ?? 0));

        $countQueryBuilder->count('uid')->from($table);

        // Apply the same WHERE conditions as the main query
        if ($pid !== null && $this->tableHasPidField($table) && !$isRootLevelOnly) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }

        // Apply language filter to count query as well
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq($languageField, $countQueryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER))
                );
            }
        }

        if ($uid !== null) {
            $uids = is_array($uid) ? $uid : [$uid];
            // Apply the same UID filtering logic for count query
            $currentWorkspace = $this->getBackendUser()->workspace ?? 0;
            if ($currentWorkspace > 0 && !empty($this->getTableCtrlArray($table)['versioningWS'] ?? false)) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->or(
                        $countQueryBuilder->expr()->in('uid', $countQueryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                        $countQueryBuilder->expr()->in('t3ver_oid', $countQueryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY))
                    )
                );
            } else {
                // In live workspace, just filter by UID
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->in('uid', $countQueryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY))
                );
            }
        }

        if (!empty($filters)) {
            $this->applyFilters($countQueryBuilder, $filters, $table);
        }

        // Allow listeners to add restrictions (e.g. file mounts, tenant scopes)
        $eventDispatcher = $this->eventDispatcher;
        $eventDispatcher->dispatch(new BeforeRecordReadEvent($table, $countQueryBuilder, 'count', BeforeRecordReadEvent::SOURCE_READ));
        $eventDispatcher->dispatch(new BeforeRecordReadEvent($table, $queryBuilder, 'select', BeforeRecordReadEvent::SOURCE_READ));

        try {
            $totalCount = $countQueryBuilder->executeQuery()->fetchOne();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new DatabaseException('count', $table, $e);
        }

        // Execute the query
        try {
            $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new DatabaseException('select', $table, $e);
        }

        // Apply workspace overlay so callers see the workspace-effective row,
        // not the underlying live record. WorkspaceRestriction strips workspace
        // versions out of the result set; BackendUtility::workspaceOL() looks
        // them up and folds their fields onto the live row in place.
        $records = $this->applyWorkspaceOverlay($table, $records);

        // Allow listeners to enrich or redact rows on raw data so they see all source
        // columns (uid_local, etc.) regardless of the caller's `fields` filter. The
        // requested-fields list is passed through so listeners can short-circuit
        // expensive work the caller did not ask for. processRecord then applies the
        // schema and `fields` filters; computed (mcp.computed) fields only survive
        // when the caller explicitly listed them.
        $afterEvent = new AfterRecordReadEvent($table, $records, 'top', $requestedFields);
        $eventDispatcher->dispatch($afterEvent);
        $records = $afterEvent->getRecords();

        // Process records to handle binary data, convert types, and filter default values
        $processedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->fieldReadConverter->processRecord($record, $table, $requestedFields);
            $processedRecords[] = $processedRecord;
        }

        // Return the result with metadata
        return [
            'table' => $table,
            'tableLabel' => $this->getTableLabel($table),
            'records' => $processedRecords,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + count($records)) < $totalCount,
        ];
    }

    public function normalizeSystemFieldFilters(string $table, mixed $filters, ?int $pid): array
    {
        if ($filters === null || $filters === []) {
            return [];
        }

        if (!is_array($filters)) {
            throw new ValidationException([
                'filters must be an array of {field, operator, value} objects.',
            ]);
        }

        // If $filters is an associative object like {hidden: 0, sys_language_uid: "hu"},
        // convert each entry to a proper filter with operator "eq".
        /** @var list<mixed> $normalized */
        $normalized = [];
        $isList = array_is_list($filters);
        foreach ($filters as $key => $value) {
            if (!$isList && is_string($key)) {
                $normalized[] = ['field' => $key, 'operator' => 'eq', 'value' => $value];
            } else {
                $normalized[] = $value;
            }
        }

        $languageField = $this->tableAccessService->getLanguageFieldName($table) ?? '';

        /** @var list<mixed> $result */
        $result = [];
        foreach ($normalized as $filter) {
            if (!is_array($filter)) {
                $result[] = $filter;
                continue;
            }
            $field = is_string($filter['field'] ?? null) ? $filter['field'] : '';
            if ($field === '') {
                $result[] = $filter;
                continue;
            }

            // sys_language_uid — accept ISO codes
            if ($languageField !== '' && $field === $languageField
                && isset($filter['value']) && is_string($filter['value']) && !ctype_digit($filter['value'])
            ) {
                $iso = $filter['value'];
                $resolved = null;
                if ($pid !== null && $pid > 0) {
                    $resolved = $this->languageService->getUidFromIsoCodeForPage($pid, $iso);
                }
                if ($resolved === null) {
                    $resolved = $this->languageService->getUidFromIsoCode($iso);
                }
                if ($resolved === null) {
                    throw new ValidationException(['Unknown language code in filter: ' . $iso]);
                }
                $filter['value'] = $resolved;
            }

            // hidden — accept booleans
            if ($field === 'hidden' && isset($filter['value']) && is_bool($filter['value'])) {
                $filter['value'] = $filter['value'] ? 1 : 0;
            }

            $result[] = $filter;
        }

        return $result;
    }

    /**
     * Normalize user-provided field names to their correct case.
     *
     * Field names in TYPO3 are case-sensitive in PHP arrays but users may enter
     * them case-insensitively (e.g. "ctype" instead of "CType"). This maps each
     * requested name to the actual TCA column name or essential field name.
     * Unrecognized names are kept as-is (they simply won't match anything).
     */

    public function normalizeFieldNames(string $table, array $requestedFields): array
    {
        if (empty($requestedFields)) {
            return [];
        }

        // Build a lowercase → actual name map from TCA columns and essential fields
        $knownFields = [];
        foreach (array_keys($GLOBALS['TCA'][$table]['columns'] ?? []) as $columnName) {
            $knownFields[strtolower((string)$columnName)] = $columnName;
        }
        foreach ($this->tableAccessService->getEssentialFields($table) as $essentialName) {
            $knownFields[strtolower($essentialName)] = $essentialName;
        }

        $normalized = [];
        foreach ($requestedFields as $field) {
            $lower = strtolower((string)$field);
            $normalized[] = $knownFields[$lower] ?? $field;
        }

        return $normalized;
    }

    /**
     * Apply structured filters to a query builder using parameterized queries.
     *
     * @param QueryBuilder $queryBuilder
     * @param array $filters Array of filter definitions with field, operator, value
     * @param string $table Table name for field validation
     * @throws ValidationException
     */

    public function applyFilters(QueryBuilder $queryBuilder, array $filters, string $table): void
    {
        // Build set of valid field names for this table (TCA columns + essential fields)
        $essentialFields = $this->tableAccessService->getEssentialFields($table);
        $validFields = array_merge(array_keys($GLOBALS['TCA'][$table]['columns'] ?? []), $essentialFields);
        $validFieldsLower = [];
        foreach ($validFields as $fieldName) {
            $validFieldsLower[strtolower((string)$fieldName)] = $fieldName;
        }

        // Operators that require a value
        $valueRequiredOperators = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like', 'notLike', 'in', 'notIn'];

        foreach ($filters as $index => $filter) {
            if (!is_array($filter)) {
                throw new ValidationException(["Filter at index {$index} must be an object"]);
            }

            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;

            if (empty($field) || !is_string($field)) {
                throw new ValidationException(["Filter at index {$index} requires a 'field' string"]);
            }

            if (empty($operator) || !is_string($operator)) {
                throw new ValidationException(["Filter at index {$index} requires an 'operator' string"]);
            }

            if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                throw new ValidationException(["Filter at index {$index} has invalid operator '{$operator}'. Allowed: " . implode(', ', self::ALLOWED_OPERATORS)]);
            }

            // Validate that comparison operators have a value
            if (in_array($operator, $valueRequiredOperators, true) && $value === null) {
                throw new ValidationException(["Filter at index {$index}: operator '{$operator}' requires a 'value'"]);
            }

            // Validate field exists in table (case-insensitive lookup)
            $resolvedField = $validFieldsLower[strtolower($field)] ?? null;
            if ($resolvedField === null) {
                throw new ValidationException(["Filter at index {$index} references unknown field '{$field}' in table '{$table}'"]);
            }

            // Verify the field is accessible (not excluded by TSconfig, permissions, etc.)
            // Essential fields (uid, pid, type, label, etc.) are always allowed for filtering
            if (!in_array($resolvedField, $essentialFields, true)
                && !$this->tableAccessService->canAccessField($table, $resolvedField)
            ) {
                throw new ValidationException(["Filter at index {$index} references inaccessible field '{$resolvedField}' in table '{$table}'"]);
            }

            // Determine the parameter type from the actual PHP type — no string coercion
            $paramType = ParameterType::STRING;
            if (is_int($value)) {
                $paramType = ParameterType::INTEGER;
            } elseif (is_bool($value)) {
                $paramType = ParameterType::INTEGER;
                $value = (int)$value;
            }

            // Build the expression
            $expr = $queryBuilder->expr();
            switch ($operator) {
                case 'eq':
                    $queryBuilder->andWhere($expr->eq($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'neq':
                    $queryBuilder->andWhere($expr->neq($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'lt':
                    $queryBuilder->andWhere($expr->lt($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'lte':
                    $queryBuilder->andWhere($expr->lte($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'gt':
                    $queryBuilder->andWhere($expr->gt($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'gte':
                    $queryBuilder->andWhere($expr->gte($resolvedField, $queryBuilder->createNamedParameter($value, $paramType)));
                    break;
                case 'like':
                    $queryBuilder->andWhere($expr->like($resolvedField, $queryBuilder->createNamedParameter($value)));
                    break;
                case 'notLike':
                    $queryBuilder->andWhere($expr->notLike($resolvedField, $queryBuilder->createNamedParameter($value)));
                    break;
                case 'in':
                case 'notIn':
                    if (!is_array($value)) {
                        throw new ValidationException(["Filter at index {$index}: '{$operator}' operator requires an array value"]);
                    }
                    $arrayType = $this->isIntegerArray($value)
                        ? Connection::PARAM_INT_ARRAY
                        : Connection::PARAM_STR_ARRAY;
                    $exprMethod = $operator === 'in' ? 'in' : 'notIn';
                    $queryBuilder->andWhere($expr->$exprMethod(
                        $resolvedField,
                        $queryBuilder->createNamedParameter($value, $arrayType)
                    ));
                    break;
                case 'isNull':
                    $queryBuilder->andWhere($expr->isNull($resolvedField));
                    break;
                case 'isNotNull':
                    $queryBuilder->andWhere($expr->isNotNull($resolvedField));
                    break;
            }
        }
    }


    public function isIntegerArray(array $values): bool
    {
        return !empty($values) && array_reduce($values, static fn(bool $carry, $v): bool => $carry && is_int($v), true);
    }

    /**
     * Apply default sorting from TCA
     */

    public function applyDefaultSorting(QueryBuilder $queryBuilder, string $table): void
    {
        // Check for sortby field
        $sortbyField = $this->tableAccessService->getSortingFieldName($table);
        if ($sortbyField) {
            $queryBuilder->orderBy($sortbyField, 'ASC');
            return;
        }

        // Check for default_sortby
        $defaultSorting = $this->tableAccessService->parseDefaultSorting($table);
        if (!empty($defaultSorting)) {
            foreach ($defaultSorting as $sortConfig) {
                $queryBuilder->addOrderBy($sortConfig['field'], $sortConfig['direction']);
            }
            return;
        }

        // Default to ordering by UID
        $queryBuilder->orderBy('uid', 'ASC');
    }

    public function tableHasPidField(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        // Most tables in TYPO3 have a pid field, but some system tables don't
        // We could check the actual database schema, but for simplicity we'll use a heuristic

        // These tables definitely don't have a pid field
        $tablesWithoutPid = [
            'sys_registry', 'sys_log', 'sys_history', 'sys_file', 'be_sessions', 'fe_sessions',
        ];

        if (in_array($table, $tablesWithoutPid)) {
            return false;
        }

        return true;
    }

    public function applyWorkspaceOverlay(string $table, array $records): array
    {
        if (empty($records)) {
            return $records;
        }
        $workspaceId = (int)$this->getBackendUser()->workspace;
        if ($workspaceId <= 0) {
            return $records;
        }
        if (empty($this->getTableCtrlArray($table)['versioningWS'] ?? false)) {
            return $records;
        }

        $overlaid = [];
        foreach ($records as $row) {
            $original = $row;
            try {
                BackendUtility::workspaceOL($table, $row, $workspaceId);
            } catch (\Throwable $e) {
                // Defensive: a corrupt workspace version (e.g. binary garbage
                // in a string field on a strict driver) must not turn the
                // whole read into a hard error response. Log and keep the
                // live row.
                $this->logException($e, sprintf('applying workspace overlay on %s', $table));
                $row = $original;
            }
            if (!is_array($row)) {
                continue;
            }
            $overlaid[] = $row;
        }
        return $overlaid;
    }

}
