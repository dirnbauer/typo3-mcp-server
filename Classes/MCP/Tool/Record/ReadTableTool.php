<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Database\Query\Restriction\WorkspaceMovePointerRestriction;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for reading records from TYPO3 tables
 *
 * @phpstan-type RecordRow array<string, mixed>
 * @phpstan-type RecordRows list<RecordRow>
 */
final class ReadTableTool extends AbstractRecordTool
{
    private const MAX_WHERE_TOKENS = 5000;
    private const MAX_WHERE_CONDITIONS = 80;
    private const DISALLOWED_WHERE_PATTERN = '/(;|--|#|\/\*|\*\/|\b(?:SELECT|UNION|DROP|DELETE|UPDATE|INSERT|TRUNCATE|ALTER|CREATE|SLEEP|BENCHMARK)\b)/i';

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        protected readonly LanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('Backend user context not initialized');
        }

        return $backendUser;
    }

    protected function getCurrentWorkspaceId(): int
    {
        return $this->getBackendUser()->workspace ?? 0;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getTableColumns(string $table): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return [];
        }

        $columns = $tableConfig['columns'] ?? null;
        if (!\is_array($columns)) {
            return [];
        }

        $normalizedColumns = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (\is_string($fieldName) && \is_array($fieldConfig)) {
                $normalizedColumns[$fieldName] = $fieldConfig;
            }
        }

        return $normalizedColumns;
    }

    protected function isHiddenTcaTable(string $table): bool
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return false;
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return false;
        }

        $ctrl = $tableConfig['ctrl'] ?? null;
        if (!\is_array($ctrl)) {
            return false;
        }

        return ($ctrl['hideTable'] ?? false) === true;
    }

    /**
     * Get the tool schema
     */
    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        // Check if multiple languages are available
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        $hasMultipleLanguages = \count($availableLanguages) > 1;

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
                'description' => 'Filter by page ID (recommended - use this instead of individual record lookups)',
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Filter by record UID (use pid filter instead to read multiple records of a page)',
            ],
            'where' => [
                'type' => 'string',
                'description' => 'Restricted filter expression using field comparisons with AND/OR, LIKE, IN, and NULL checks. Example: CType = "textmedia" AND pid = 1',
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
            'description' => 'Read records from TYPO3 tables with filtering, pagination, and relation embedding. By default, returns records from ALL languages mixed together (matching TYPO3\'s list module behavior). Use the language parameter to filter to a specific language. For page content, use pid filter instead of individual record lookups.',
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
    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Validate table access
        $table = \is_string($params['table'] ?? null) ? $params['table'] : '';
        if (empty($table)) {
            throw new ValidationException(['Table name is required']);
        }

        $this->ensureTableAccess($table, 'read');

        // Execute main logic
        // Extract and validate parameters
        $pid = isset($params['pid']) && is_numeric($params['pid']) ? (int)$params['pid'] : null;
        $uid = isset($params['uid']) && is_numeric($params['uid']) ? (int)$params['uid'] : null;
        $condition = \is_string($params['where'] ?? null) ? $params['where'] : '';
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) && is_numeric($params['offset']) ? (int)$params['offset'] : 0;
        $language = \is_string($params['language'] ?? null) ? $params['language'] : null;
        $includeTranslationSource = (bool)($params['includeTranslationSource'] ?? false);
        $rawRequestedFields = \is_array($params['fields'] ?? null) ? $params['fields'] : [];
        $requestedFields = $this->normalizeFieldNames(
            $table,
            array_values(array_filter($rawRequestedFields, is_string(...))),
        );

        // Ensure translation parent field is included when translation source is requested
        if ($includeTranslationSource && !empty($requestedFields)) {
            $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
            if ($translationParentField && !\in_array($translationParentField, $requestedFields)) {
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

        // Convert language ISO code to UID if provided
        $languageUid = null;
        if ($language !== null) {
            $languageUid = $this->languageService->getUidFromIsoCode($language);
            if ($languageUid === null) {
                throw new ValidationException(["Unknown language code: {$language}"]);
            }
        }

        // Get records from the table
        $result = $this->getRecords(
            $table,
            $pid,
            $uid,
            $condition,
            $limit,
            $offset,
            $languageUid,
            $requestedFields,
        );

        // Include related records
        $result = $this->includeRelations($result, $table, $requestedFields);

        // Include translation metadata if requested
        if ($includeTranslationSource && $languageUid !== null && $languageUid > 0) {
            /** @var RecordRows $translationRecords */
            $translationRecords = array_values(array_filter(
                \is_array($result['records'] ?? null) ? $result['records'] : [],
                is_array(...),
            ));
            $result['translationSource'] = $this->getTranslationSourceData($translationRecords, $table);
        }

        // Return the result as JSON
        return $this->createJsonResult($result);
    }

    /**
     * Get records from a table
     */
    /**
     * @param list<string> $requestedFields
     * @return array{table: string, tableLabel: string, records: RecordRows, total: int, limit: int, offset: int, hasMore: bool}
     */
    protected function getRecords(
        string $table,
        ?int $pid,
        ?int $uid,
        string $condition,
        int $limit,
        int $offset,
        ?int $languageUid = null,
        array $requestedFields = [],
    ): array {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceMovePointerRestriction::class, $this->getCurrentWorkspaceId()));

        // Always include hidden records (like the TYPO3 backend does)

        // Select all fields
        $queryBuilder->select('*')
            ->from($table);

        // Filter by pid if specified
        if ($pid !== null && $this->tableHasPidField($table)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        // Filter by language if specified and table has language field
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER)),
                );
            }
        }

        // Filter by uid if specified
        if ($uid !== null) {
            // For workspace transparency, we need to handle both cases:
            // 1. The UID is a workspace UID (for new records)
            // 2. The UID is a live UID (for existing records with workspace versions)

            $currentWorkspace = $this->getCurrentWorkspaceId();
            if ($currentWorkspace > 0) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    ),
                );
            } else {
                // In live workspace, just filter by UID
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                );
            }
        }

        $this->applyConditionFilter($queryBuilder, $table, $condition);

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
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceMovePointerRestriction::class, $this->getCurrentWorkspaceId()));

        $countQueryBuilder->count('uid')->from($table);

        // Apply the same WHERE conditions as the main query
        if ($pid !== null && $this->tableHasPidField($table)) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        // Apply language filter to count query as well
        if ($languageUid !== null) {
            $languageField = $this->tableAccessService->getLanguageFieldName($table);
            if (!empty($languageField)) {
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq($languageField, $countQueryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER)),
                );
            }
        }

        if ($uid !== null) {
            // Apply the same UID filtering logic for count query
            $currentWorkspace = $this->getCurrentWorkspaceId();
            if ($currentWorkspace > 0) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->or(
                        $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $countQueryBuilder->expr()->eq('t3ver_oid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    ),
                );
            } else {
                // In live workspace, just filter by UID
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                );
            }
        }

        $this->applyConditionFilter($countQueryBuilder, $table, $condition);

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

        // Process records to handle binary data, convert types, and filter default values
        $processedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->processRecord($record, $table, $requestedFields);
            $processedRecords[] = $processedRecord;
        }

        // Return the result with metadata
        return [
            'table' => $table,
            'tableLabel' => $this->getTableLabel($table),
            'records' => $processedRecords,
            'total' => is_numeric($totalCount) ? (int)$totalCount : 0,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + \count($records)) < (is_numeric($totalCount) ? (int)$totalCount : 0),
        ];
    }

    /**
     * Process a raw database record into a filtered, converted result.
     *
     * Applies two layers of field filtering:
     * 1. TCA type filtering — fields not in the record type's showitem definition are excluded.
     *    Essential fields (uid, pid, type, label, timestamps, hidden, sorting) are merged into
     *    the type-specific set so they always pass this check — TCA showitem doesn't declare them
     *    because they're ctrl fields not shown in backend forms, but they're valid to read.
     *    canAccessField() is also enforced here since getFieldNamesForType() already strips
     *    inaccessible fields (file fields, inline to restricted tables, TSconfig-disabled, etc.).
     * 2. Requested fields — optional user-provided whitelist that narrows the result further.
     *    When provided, uid is always added. When empty, all fields from step 1 are returned.
     *
     * @param RecordRow $record Raw database row
     * @param string $table Table name
     * @param list<string> $requestedFields User-provided field whitelist from the "fields" tool parameter.
     *                               Empty = no additional filtering (default behavior).
     * @return RecordRow
     */
    protected function processRecord(array $record, string $table, array $requestedFields = []): array
    {
        $processedRecord = [];

        // For workspace transparency, replace workspace UID with live UID
        if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
            // This is a workspace version of an existing record - use the live UID instead
            $record['uid'] = $record['t3ver_oid'];
        } elseif (isset($record['t3ver_state']) && $record['t3ver_state'] == 1) {
            // This is a new record in workspace - keep its UID as is
            // New records don't have a live counterpart until published
            // No change needed
        }

        // Ensure uid is always in the requested fields when a field list is specified
        if (!empty($requestedFields) && !\in_array('uid', $requestedFields)) {
            $requestedFields[] = 'uid';
        }

        // Get type-specific fields if a type field exists.
        // Essential fields (uid, pid, timestamps, etc.) are merged in because TCA showitem
        // doesn't declare ctrl fields, but they are valid to read.
        $essentialFields = $this->tableAccessService->getEssentialFields($table);
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $typeSpecificFields = [];
        $hasValidTypeConfig = false;

        if ($typeField && isset($record[$typeField])) {
            $recordType = \is_scalar($record[$typeField]) ? (string)$record[$typeField] : '';
            $typeSpecificFields = $this->tableAccessService->getFieldNamesForType($table, $recordType);
            $hasValidTypeConfig = !empty($typeSpecificFields);

            if ($hasValidTypeConfig) {
                $typeSpecificFields = array_unique(array_merge($typeSpecificFields, $essentialFields));
            }
        }

        // Process each field
        foreach ($record as $field => $value) {
            // Special handling for pi_flexform in plugin content elements.
            if ($field === 'pi_flexform' && $table === 'tt_content' && $this->hasConfiguredFlexForm($record)) {
                $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
                continue;
            }

            // Skip fields not relevant to this record type (only if we have a valid type configuration)
            if ($hasValidTypeConfig && !\in_array($field, $typeSpecificFields)) {
                continue;
            }

            // Skip fields not in the requested field list
            if (!empty($requestedFields) && !\in_array($field, $requestedFields)) {
                continue;
            }

            // Include the field
            $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
        }

        return $processedRecord;
    }

    /**
     * Determine whether a tt_content record has a configured FlexForm data structure.
     */
    /**
     * @param RecordRow $record
     */
    protected function hasConfiguredFlexForm(array $record): bool
    {
        $piFlexformField = $this->getTableColumns('tt_content')['pi_flexform'] ?? [];
        $piFlexformConfig = isset($piFlexformField['config']) && \is_array($piFlexformField['config']) ? $piFlexformField['config'] : [];
        $flexFormDs = isset($piFlexformConfig['ds']) && \is_array($piFlexformConfig['ds']) ? $piFlexformConfig['ds'] : [];
        foreach ($this->getFlexFormIdentifierCandidates($record) as $candidate) {
            if (isset($flexFormDs[$candidate])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get possible FlexForm identifier keys for a plugin record.
     *
     * @return list<string>
     */
    /**
     * @param RecordRow $record
     * @return list<string>
     */
    protected function getFlexFormIdentifierCandidates(array $record): array
    {
        $candidates = [];
        $cType = \is_scalar($record['CType'] ?? null) ? (string)$record['CType'] : '';
        $listType = \is_scalar($record['list_type'] ?? null) ? (string)$record['list_type'] : '';

        if ($listType !== '') {
            $candidates[] = $listType . ',list';
            $candidates[] = '*,' . $listType;
            $candidates[] = $listType;
        }

        if ($cType !== '') {
            $candidates[] = '*,' . $cType;
            $candidates[] = $cType;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Convert a field value to the appropriate type
     */
    protected function convertFieldValue(string $table, string $field, mixed $value): mixed
    {
        // Skip null values
        if ($value === null) {
            return null;
        }

        // Check if this is an integer field based on TCA eval rules or select field with integer values
        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $field);
        if ($fieldConfig) {
            $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            // Check eval rules for int
            $eval = \is_string($fieldOptions['eval'] ?? null) ? $fieldOptions['eval'] : '';
            if ($eval !== '' && str_contains($eval, 'int')) {
                return is_numeric($value) ? (int)$value : $value;
            }

            // Check if it's a select field with numeric string that should be integer
            if (($fieldOptions['type'] ?? null) === 'select') {
                // If the value is numeric, check if this field typically uses integers
                if (is_numeric($value)) {
                    // Special handling for common integer fields
                    if (\in_array($field, ['type', 'sys_language_uid', 'colPos', 'layout', 'frame_class', 'space_before_class', 'space_after_class', 'header_layout'])) {
                        return (int)$value;
                    }

                    // Check if ALL items use integer values (not just one)
                    if (!empty($fieldOptions['items']) && \is_array($fieldOptions['items'])) {
                        $allIntegers = true;
                        $hasItems = false;

                        foreach ($fieldOptions['items'] as $item) {
                            $itemValue = null;
                            if (\is_array($item) && isset($item['value'])) {
                                $itemValue = $item['value'];
                            } elseif (\is_array($item) && isset($item[1])) {
                                $itemValue = $item[1];
                            }

                            if ($itemValue !== null && $itemValue !== '--div--') {
                                $hasItems = true;
                                if (!\is_int($itemValue) && !(\is_scalar($itemValue) && ctype_digit((string)$itemValue))) {
                                    $allIntegers = false;
                                    break;
                                }
                            }
                        }

                        // Only convert if all items are integers
                        if ($hasItems && $allIntegers) {
                            return (int)$value;
                        }
                    }
                }
            }
        }

        // Convert FlexForm XML to JSON
        if ($this->tableAccessService->isFlexFormField($table, $field) && \is_string($value) && !empty($value) && str_starts_with($value, '<?xml')) {
            try {
                // Use TYPO3's FlexFormService to convert XML to array
                $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
                $flexFormArray = $flexFormService->convertFlexFormContentToArray($value);

                // Simplify the structure for easier use in LLMs
                $result = [];
                $settings = [];

                // Process each field and organize settings
                foreach ($flexFormArray as $key => $val) {
                    // Check if this is a settings field (key starts with "settings")
                    if (str_starts_with($key, 'settings') && \strlen($key) > 8) {
                        // Extract the setting name (remove "settings" prefix)
                        $settingName = (string)substr($key, 8);
                        // Convert first character to lowercase if it's uppercase
                        if ($settingName !== '' && ctype_upper($settingName[0])) {
                            $settingName = lcfirst($settingName);
                        }
                        $settings[$settingName] = $val;
                    } else {
                        $result[$key] = $val;
                    }
                }

                // Add settings to result if any were found
                if (!empty($settings)) {
                    $result['settings'] = $settings;
                }

                return $result;
            } catch (\Exception $e) {
                // Log the error but continue with empty result
                $this->logException($e, 'parsing flexform XML');
                return [];
            }
        }

        // Convert JSON strings to arrays
        if (\is_string($value) && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return \is_array($decoded) ? $decoded : $value;
            }
        }

        // Convert timestamps to ISO 8601 dates
        if (is_numeric($value) && $this->tableAccessService->isDateField($table, $field)) {
            if ($value > 0) {
                $dateTime = new \DateTime('@' . $value);
                $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $dateTime->format('c');
            }
            return null;
        }

        return $value;
    }

    /**
     * Normalize user-provided field names to their correct case.
     *
     * Field names in TYPO3 are case-sensitive in PHP arrays but users may enter
     * them case-insensitively (e.g. "ctype" instead of "CType"). This maps each
     * requested name to the actual TCA column name or essential field name.
     * Unrecognized names are kept as-is (they simply won't match anything).
     */
    /**
     * @param list<string> $requestedFields
     * @return list<string>
     */
    protected function normalizeFieldNames(string $table, array $requestedFields): array
    {
        if (empty($requestedFields)) {
            return [];
        }

        // Build a lowercase → actual name map from TCA columns and essential fields
        $knownFields = [];
        foreach (array_keys($this->getTableColumns($table)) as $columnName) {
            $knownFields[strtolower($columnName)] = $columnName;
        }
        foreach ($this->tableAccessService->getEssentialFields($table) as $essentialName) {
            $knownFields[strtolower($essentialName)] = $essentialName;
        }

        $normalized = [];
        foreach ($requestedFields as $field) {
            $lower = strtolower($field);
            $normalized[] = $knownFields[$lower] ?? $field;
        }

        return $normalized;
    }

    /**
     * Apply default sorting from TCA
     */
    protected function applyDefaultSorting(QueryBuilder $queryBuilder, string $table): void
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

    /**
     * Include related records in the result
     */
    /**
     * @param array{records: RecordRows}|array<string, mixed> $result
     * @param list<string> $requestedFields
     * @return array<string, mixed>
     */
    protected function includeRelations(array $result, string $table, array $requestedFields = []): array
    {
        if (empty($result['records'])) {
            return $result;
        }

        $columns = $this->getTableColumns($table);
        if ($columns === []) {
            return $result;
        }

        // Get all record UIDs
        /** @var RecordRows $recordRows */
        $recordRows = array_values(array_filter(
            \is_array($result['records']) ? $result['records'] : [],
            is_array(...),
        ));
        $recordUids = [];
        foreach ($recordRows as $record) {
            if (\is_int($record['uid'] ?? null)) {
                $recordUids[] = $record['uid'];
            }
        }

        // Process each field that might contain relations
        foreach ($columns as $fieldName => $fieldConfig) {
            // Skip relations for fields not in the requested field list
            if (!empty($requestedFields) && !\in_array($fieldName, $requestedFields)) {
                continue;
            }

            $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $fieldType = \is_string($fieldOptions['type'] ?? null) ? $fieldOptions['type'] : '';

            match ($fieldType) {
                'select', 'category' => $this->includeSelectRelations($recordRows, $fieldName, $fieldConfig, $table),
                'inline' => $this->includeInlineRelations($recordRows, $fieldName, $fieldConfig, $recordUids),
                default => null,
            };
        }

        $result['records'] = $recordRows;

        return $result;
    }

    /**
     * Include select and category field relations
     */
    /**
     * @param RecordRows $records
     * @param array<string, mixed> $fieldConfig
     */
    protected function includeSelectRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        // Check if this is a foreign table relation
        if (!empty($fieldOptions['foreign_table']) && \is_string($fieldOptions['foreign_table'])) {
            $foreignTable = $fieldOptions['foreign_table'];

            // Skip if the foreign table doesn't exist or isn't accessible
            if (!$this->tableAccessService->canAccessTable($foreignTable)) {
                return;
            }

            // Check if this uses MM relations
            if (!empty($fieldOptions['MM'])) {
                $this->includeMmRelations($records, $fieldName, $fieldConfig, $table);
                return;
            }

            // Regular foreign table relation without MM
            $this->includeRegularRelations($records, $fieldName, $fieldConfig);
            return;
        }

        // Handle static items (options from TCA, not from a foreign table)
        if (!empty($fieldOptions['items'])) {
            $this->includeStaticItems($records, $fieldName, $fieldConfig);
        }
    }

    /**
     * Include MM relations for a field
     */
    /**
     * @param RecordRows $records
     * @param array<string, mixed> $fieldConfig
     */
    protected function includeMmRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $mmTable = \is_string($fieldOptions['MM'] ?? null) ? $fieldOptions['MM'] : '';
        if ($mmTable === '') {
            return;
        }

        // Get MM values for all records
        foreach ($records as &$record) {
            $record[$fieldName] = [];
            if (\is_int($record['uid'] ?? null)) {
                $mmValues = $this->getMmRelationValues(
                    $mmTable,
                    $table,
                    $record['uid'],
                    $fieldName,
                    $fieldOptions,
                );
                $record[$fieldName] = $mmValues;
            }
        }
    }

    /**
     * Include regular (non-MM) relations for a field
     */
    /**
     * @param RecordRows $records
     * @param array<string, mixed> $fieldConfig
     */
    protected function includeRegularRelations(array &$records, string $fieldName, array $fieldConfig): void
    {
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        // Check if this field supports multiple values
        $supportsMultiple = false;
        if (is_numeric($fieldOptions['maxitems'] ?? null) && (int)$fieldOptions['maxitems'] > 1) {
            $supportsMultiple = true;
        }
        if (!empty($fieldOptions['multiple'])) {
            $supportsMultiple = true;
        }

        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName])) {
                if ($supportsMultiple) {
                    // Multi-select field - convert to array
                    $fieldValue = $record[$fieldName];
                    if (!\is_scalar($fieldValue) || $fieldValue === '' || $fieldValue === 0 || $fieldValue === '0') {
                        $record[$fieldName] = [];
                    } elseif (\is_int($fieldValue)) {
                        $record[$fieldName] = [$fieldValue];
                    } else {
                        $values = GeneralUtility::intExplode(',', (string)$fieldValue, true);
                        $record[$fieldName] = $values;
                    }
                } else {
                    // Single-select field - keep as single value
                    if (\is_string($record[$fieldName]) && str_contains($record[$fieldName], ',')) {
                        // If there's a comma, take only the first value
                        $values = GeneralUtility::intExplode(',', $record[$fieldName], true);
                        $record[$fieldName] = !empty($values) ? $values[0] : 0;
                    } else {
                        // Convert to integer if numeric
                        if (is_numeric($record[$fieldName])) {
                            $record[$fieldName] = (int)$record[$fieldName];
                        }
                    }
                }
            }
        }
    }

    /**
     * Include static items for a field
     */
    /**
     * @param RecordRows $records
     * @param array<string, mixed> $fieldConfig
     */
    protected function includeStaticItems(array &$records, string $fieldName, array $fieldConfig): void
    {
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName]) && \is_scalar($record[$fieldName]) && $record[$fieldName] !== '') {
                // Convert to array if it's a multi-select field
                if (!empty($fieldOptions['multiple'])) {
                    $values = GeneralUtility::trimExplode(',', (string)$record[$fieldName], true);
                    $record[$fieldName] = $values;
                }
                // Single select fields remain as single values
            }
        }
    }

    /**
     * Include inline field relations
     */
    /**
     * @param RecordRows $records
     * @param array<string, mixed> $fieldConfig
     * @param list<int> $recordUids
     */
    protected function includeInlineRelations(array &$records, string $fieldName, array $fieldConfig, array $recordUids): void
    {
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        if (empty($fieldOptions['foreign_table']) || !\is_string($fieldOptions['foreign_table'])) {
            return;
        }

        $foreignTable = $fieldOptions['foreign_table'];
        $foreignField = \is_string($fieldOptions['foreign_field'] ?? null) ? $fieldOptions['foreign_field'] : '';

        // Skip if the foreign table isn't accessible or no foreign field
        if (!$this->tableAccessService->canAccessTable($foreignTable) || empty($foreignField)) {
            return;
        }

        // Check if foreign table is hidden (dependent records that should be embedded)
        $isHiddenTable = $this->isHiddenTcaTable($foreignTable);

        // Get all related records
        $foreignSortBy = \is_string($fieldOptions['foreign_sortby'] ?? null) ? $fieldOptions['foreign_sortby'] : '';
        $relatedRecords = $this->getInlineRelatedRecords($foreignTable, $foreignField, $recordUids, $foreignSortBy);

        // Group related records by parent record
        $groupedRecords = [];
        foreach ($relatedRecords as $relatedRecord) {
            $parentUid = $relatedRecord[$foreignField] ?? null;
            if (\is_int($parentUid)) {
                if (!isset($groupedRecords[$parentUid])) {
                    $groupedRecords[$parentUid] = [];
                }
                $groupedRecords[$parentUid][] = $relatedRecord;
            }
        }

        // Add related records to each record
        foreach ($records as &$record) {
            $uid = $record['uid'] ?? null;
            if (\is_int($uid)) {
                if (isset($groupedRecords[$uid])) {
                    if ($isHiddenTable) {
                        // Embed full records for hidden tables (like sys_file_reference)
                        $record[$fieldName] = $groupedRecords[$uid];
                    } else {
                        // Return only UIDs for independent tables (like tt_content)
                        $record[$fieldName] = array_column($groupedRecords[$uid], 'uid');
                    }
                } else {
                    // Initialize as empty array if field exists in record but no relations found
                    if (\array_key_exists($fieldName, $record)) {
                        $record[$fieldName] = [];
                    }
                }
            }
        }
    }

    /**
     * Get inline related records
     */
    /**
     * @param list<int> $parentUids
     * @return RecordRows
     */
    protected function getInlineRelatedRecords(string $table, string $foreignField, array $parentUids, string $foreignSortBy = ''): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = $this->connectionPool;

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        // For inline relations, we need proper workspace handling
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()));

        // Select all fields
        // Apply default sorting if foreign_sortby is defined

        if (empty($foreignSortBy)) {
            $this->applyDefaultSorting($queryBuilder, $table);
        } else {
            $queryBuilder->orderBy($foreignSortBy, 'ASC')
                ->addOrderBy('uid', 'ASC');  // Secondary sort by UID for consistency;
        }

        // Ensure we have the sort field in our select
        $records = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    $foreignField,
                    $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Process records for workspace transparency
        $processedRecords = [];
        foreach ($records as $record) {
            $processed = $this->processRecord($record, $table);

            // Ensure the foreign field is always included if it exists in the raw record
            if (isset($record[$foreignField]) && !isset($processed[$foreignField])) {
                $processed[$foreignField] = $this->convertFieldValue($table, $foreignField, $record[$foreignField]);
            }

            $processedRecords[] = $processed;
        }

        return $processedRecords;
    }

    /**
     * Get MM relation values for a field
     *
     * NOTE: This method provides basic MM relation support. It does NOT:
     * - Apply foreign_table_where conditions
     * - Resolve placeholders in foreign_table_where
     * - Handle complex TYPO3 relation scenarios
     *
     * For complex scenarios, use TYPO3 Backend or DataHandler which handle
     * these complexities properly.
     *
     * @param string $mmTable The MM table name
     * @param string $localTable The local table name
     * @param int $localUid The local record UID
     * @param string $fieldName The field name (for documentation)
     * @param array<string, mixed> $fieldConfig The full field configuration from TCA
     * @return list<int> Array of related UIDs (not full records)
     */
    protected function getMmRelationValues(string $mmTable, string $localTable, int $localUid, string $fieldName, array $fieldConfig): array
    {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($mmTable);

        // Determine if this is an opposite/reverse relation
        $isOppositeRelation = !empty($fieldConfig['MM_opposite_field']);

        // Set column names based on relation direction
        if ($isOppositeRelation) {
            // For opposite relations (like categories), local record is in uid_foreign
            $localColumn = 'uid_foreign';
            $foreignColumn = 'uid_local';
            $sortingColumn = 'sorting_foreign';
        } else {
            // For standard relations (like tags), local record is in uid_local
            $localColumn = 'uid_local';
            $foreignColumn = 'uid_foreign';
            $sortingColumn = 'sorting';
        }

        // Basic constraints
        $constraints = [
            $queryBuilder->expr()->eq($localColumn, $queryBuilder->createNamedParameter($localUid, ParameterType::INTEGER)),
        ];

        // Add match fields if specified (e.g., for shared MM tables like sys_category_record_mm)
        $matchFields = isset($fieldConfig['MM_match_fields']) && \is_array($fieldConfig['MM_match_fields']) ? $fieldConfig['MM_match_fields'] : [];
        if ($mmTable === 'sys_category_record_mm') {
            $matchFields['tablenames'] ??= $localTable;
            $matchFields['fieldname'] ??= $fieldName;
        }
        foreach ($matchFields as $field => $value) {
            if (!\is_string($field)) {
                continue;
            }
            $constraints[] = $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value),
            );
        }

        // Execute query
        $result = $queryBuilder
            ->select($foreignColumn)
            ->from($mmTable)
            ->where(...$constraints)
            ->orderBy($sortingColumn, 'ASC')
            ->executeQuery();

        $values = [];
        while ($row = $result->fetchAssociative()) {
            if (is_numeric($row[$foreignColumn] ?? null)) {
                $values[] = (int)$row[$foreignColumn];
            }
        }

        return $values;
    }

    /**
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
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

        if (\in_array($table, $tablesWithoutPid)) {
            return false;
        }

        return true;
    }

    /**
     * Get translation source data for records
     */
    /**
     * @param RecordRows $records
     * @return array<int, array{sourceUid: int, sourceLanguage: string, inheritedFields: array<string, mixed>}>
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
                if (is_numeric($record[$translationParentField])) {
                    $parentUids[] = (int)$record[$translationParentField];
                }
            }
        }

        if (empty($parentUids)) {
            return [];
        }

        // Load parent records
        $parentRecords = $this->loadParentRecords($table, array_values(array_unique($parentUids)));

        // Build translation metadata
        foreach ($records as $record) {
            if (!empty($record[$translationParentField])) {
                $parentUid = is_numeric($record[$translationParentField]) ? (int)$record[$translationParentField] : 0;
                $recordUid = is_numeric($record['uid'] ?? null) ? (int)$record['uid'] : 0;
                if ($parentUid <= 0 || $recordUid <= 0) {
                    continue;
                }

                if (isset($parentRecords[$parentUid])) {
                    $parentRecord = $parentRecords[$parentUid];

                    // Get excluded and synchronized fields
                    $excludedFields = $this->tableAccessService->getExcludedFieldsInTranslation($table);
                    $inheritedValues = [];

                    // Collect inherited field values
                    foreach ($excludedFields as $field) {
                        if (isset($parentRecord[$field])) {
                            $inheritedValues[$field] = $this->convertFieldValue($table, $field, $parentRecord[$field]);
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
    /**
     * @param list<int> $parentUids
     * @return array<int, RecordRow>
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
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceMovePointerRestriction::class, $this->getCurrentWorkspaceId()));

        $records = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Process and index by UID
        $indexedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->processRecord($record, $table);
            $processedUid = is_numeric($processedRecord['uid'] ?? null) ? (int)$processedRecord['uid'] : 0;
            if ($processedUid > 0) {
                $indexedRecords[$processedUid] = $processedRecord;
            }
        }

        return $indexedRecords;
    }

    private function applyConditionFilter(QueryBuilder $queryBuilder, string $table, string $condition): void
    {
        $trimmedCondition = trim($condition);
        if ($trimmedCondition === '') {
            return;
        }

        if (preg_match(self::DISALLOWED_WHERE_PATTERN, $trimmedCondition) === 1) {
            throw new \InvalidArgumentException('The condition contains disallowed SQL keywords');
        }

        $tokens = $this->tokenizeCondition($trimmedCondition);
        if (\count($tokens) > self::MAX_WHERE_TOKENS) {
            throw new \InvalidArgumentException('The condition is too complex');
        }

        $offset = 0;
        $conditionCount = 0;
        $expression = $this->parseConditionExpression($queryBuilder, $table, $tokens, $offset, $conditionCount);

        if ($offset !== \count($tokens)) {
            throw new \InvalidArgumentException('The condition uses unsupported syntax');
        }

        if ($conditionCount > self::MAX_WHERE_CONDITIONS) {
            throw new \InvalidArgumentException('The condition is too complex');
        }

        $queryBuilder->andWhere($expression);
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenizeCondition(string $condition): array
    {
        $pattern = '/\s+|(?P<LPAREN>\()|(?P<RPAREN>\))|(?P<COMMA>,)|(?P<OP><=|>=|!=|<>|=|<|>)|(?P<STRING>\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")|(?P<NUMBER>-?\d+)|(?P<KEYWORD>\bAND\b|\bOR\b|\bIN\b|\bLIKE\b|\bIS\b|\bNOT\b|\bNULL\b)|(?P<IDENTIFIER>[A-Za-z_][A-Za-z0-9_]*)/i';
        preg_match_all($pattern, $condition, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];
        $cursor = 0;
        foreach ($matches as $match) {
            $fullMatch = $match[0][0];
            $offset = $match[0][1];

            if ($offset !== $cursor) {
                throw new \InvalidArgumentException('The condition uses unsupported syntax');
            }

            $cursor += \strlen($fullMatch);
            if (trim($fullMatch) === '') {
                continue;
            }

            foreach (['LPAREN', 'RPAREN', 'COMMA', 'OP', 'STRING', 'NUMBER', 'KEYWORD', 'IDENTIFIER'] as $type) {
                if (!isset($match[$type])) {
                    continue;
                }
                $value = $match[$type][0];
                $valueOffset = $match[$type][1];
                if ($valueOffset >= 0) {
                    $tokens[] = [
                        'type' => $type,
                        'value' => $type === 'KEYWORD' ? strtoupper($value) : $value,
                    ];
                    break;
                }
            }
        }

        if ($cursor !== \strlen($condition)) {
            throw new \InvalidArgumentException('The condition uses unsupported syntax');
        }

        return $tokens;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function parseConditionExpression(
        QueryBuilder $queryBuilder,
        string $table,
        array $tokens,
        int &$offset,
        int &$conditionCount,
    ): string {
        $parts = [
            $this->parseConditionAndExpression($queryBuilder, $table, $tokens, $offset, $conditionCount),
        ];

        while ($this->peekTokenValue($tokens, $offset) === 'OR') {
            $offset++;
            $parts[] = $this->parseConditionAndExpression($queryBuilder, $table, $tokens, $offset, $conditionCount);
        }

        if (\count($parts) === 1) {
            return $parts[0];
        }

        return (string)$queryBuilder->expr()->or(...$parts);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function parseConditionAndExpression(
        QueryBuilder $queryBuilder,
        string $table,
        array $tokens,
        int &$offset,
        int &$conditionCount,
    ): string {
        $parts = [
            $this->parseConditionPrimary($queryBuilder, $table, $tokens, $offset, $conditionCount),
        ];

        while ($this->peekTokenValue($tokens, $offset) === 'AND') {
            $offset++;
            $parts[] = $this->parseConditionPrimary($queryBuilder, $table, $tokens, $offset, $conditionCount);
        }

        if (\count($parts) === 1) {
            return $parts[0];
        }

        return (string)$queryBuilder->expr()->and(...$parts);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function parseConditionPrimary(
        QueryBuilder $queryBuilder,
        string $table,
        array $tokens,
        int &$offset,
        int &$conditionCount,
    ): string {
        if ($this->peekTokenType($tokens, $offset) === 'LPAREN') {
            $offset++;
            $expression = $this->parseConditionExpression($queryBuilder, $table, $tokens, $offset, $conditionCount);
            $this->expectToken($tokens, $offset, 'RPAREN');
            return $expression;
        }

        return $this->parseSingleCondition($queryBuilder, $table, $tokens, $offset, $conditionCount);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function parseSingleCondition(
        QueryBuilder $queryBuilder,
        string $table,
        array $tokens,
        int &$offset,
        int &$conditionCount,
    ): string {
        $field = $this->expectToken($tokens, $offset, 'IDENTIFIER');
        if (!$this->isFilterableField($table, $field)) {
            throw new \InvalidArgumentException(\sprintf('The condition references an unsupported field: %s', $field));
        }

        $conditionCount++;
        if ($conditionCount > self::MAX_WHERE_CONDITIONS) {
            throw new \InvalidArgumentException('The condition is too complex');
        }

        $keyword = $this->peekTokenValue($tokens, $offset);
        if ($keyword === 'IS') {
            $offset++;
            $isNot = false;
            if ($this->peekTokenValue($tokens, $offset) === 'NOT') {
                $offset++;
                $isNot = true;
            }

            $this->expectTokenValue($tokens, $offset, 'NULL');
            return $isNot
                ? (string)$queryBuilder->expr()->isNotNull($field)
                : (string)$queryBuilder->expr()->isNull($field);
        }

        if ($keyword === 'IN') {
            $offset++;
            $this->expectToken($tokens, $offset, 'LPAREN');

            $parameters = [];
            do {
                $parameters[] = $this->createLiteralParameter($queryBuilder, $tokens, $offset);
                if ($this->peekTokenType($tokens, $offset) !== 'COMMA') {
                    break;
                }
                $offset++;
            } while (true);

            $this->expectToken($tokens, $offset, 'RPAREN');
            return (string)$queryBuilder->expr()->in($field, implode(', ', $parameters));
        }

        if ($keyword === 'LIKE') {
            $offset++;
            $parameter = $this->createLiteralParameter($queryBuilder, $tokens, $offset);
            return (string)$queryBuilder->expr()->like($field, $parameter);
        }

        $operator = $this->expectToken($tokens, $offset, 'OP');
        $parameter = $this->createLiteralParameter($queryBuilder, $tokens, $offset);

        return match ($operator) {
            '=' => (string)$queryBuilder->expr()->eq($field, $parameter),
            '!=', '<>' => (string)$queryBuilder->expr()->neq($field, $parameter),
            '>' => (string)$queryBuilder->expr()->gt($field, $parameter),
            '>=' => (string)$queryBuilder->expr()->gte($field, $parameter),
            '<' => (string)$queryBuilder->expr()->lt($field, $parameter),
            '<=' => (string)$queryBuilder->expr()->lte($field, $parameter),
            default => throw new \InvalidArgumentException('The condition uses unsupported syntax'),
        };
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function createLiteralParameter(QueryBuilder $queryBuilder, array $tokens, int &$offset): string
    {
        $tokenType = $this->peekTokenType($tokens, $offset);
        $tokenValue = $this->peekTokenValue($tokens, $offset);

        if ($tokenType === 'NUMBER' && $tokenValue !== null) {
            $offset++;
            return (string)$queryBuilder->createNamedParameter((int)$tokenValue, ParameterType::INTEGER);
        }

        if ($tokenType === 'STRING' && $tokenValue !== null) {
            $offset++;
            $unquotedValue = substr($tokenValue, 1, -1);
            return (string)$queryBuilder->createNamedParameter(stripcslashes($unquotedValue), ParameterType::STRING);
        }

        throw new \InvalidArgumentException('The condition uses unsupported syntax');
    }

    private function isFilterableField(string $table, string $field): bool
    {
        if (\in_array($field, ['uid', 'pid', 't3ver_oid', 't3ver_state', 'sorting', 'crdate', 'tstamp', 'deleted', 'hidden'], true)) {
            return true;
        }

        $columns = $this->getTableColumns($table);
        return isset($columns[$field]) && $this->tableAccessService->canAccessField($table, $field);
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function expectToken(array $tokens, int &$offset, string $expectedType): string
    {
        $tokenType = $this->peekTokenType($tokens, $offset);
        $tokenValue = $this->peekTokenValue($tokens, $offset);
        if ($tokenType !== $expectedType || $tokenValue === null) {
            throw new \InvalidArgumentException('The condition uses unsupported syntax');
        }

        $offset++;
        return $tokenValue;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function expectTokenValue(array $tokens, int &$offset, string $expectedValue): string
    {
        $tokenValue = $this->peekTokenValue($tokens, $offset);
        if ($tokenValue !== $expectedValue) {
            throw new \InvalidArgumentException('The condition uses unsupported syntax');
        }

        $offset++;
        return $expectedValue;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function peekTokenType(array $tokens, int $offset): ?string
    {
        return $tokens[$offset]['type'] ?? null;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     */
    private function peekTokenValue(array $tokens, int $offset): ?string
    {
        return $tokens[$offset]['value'] ?? null;
    }

}
