<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Utility\RecordFormattingUtility;
use InvalidArgumentException;
use Mcp\Types\CallToolResult;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for searching records across TYPO3 tables using TCA-based searchable fields
 *
 * @phpstan-type SearchRecord array<string, mixed>
 * @phpstan-type SearchResult array{records: list<SearchRecord>, total: int, search_terms: list<string>, term_logic: string}
 * @phpstan-type InlineTableMetadata array{table: string, parent_table: string, parent_field: string, foreign_field: string, relation_type?: string}
 */
final class SearchTool extends AbstractRecordTool
{
    protected LanguageService $languageService;

    public function __construct()
    {
        parent::__construct();
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    protected function getCurrentWorkspaceId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? ($backendUser->workspace ?? 0) : 0;
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

    protected function tableExistsInTca(string $table): bool
    {
        return $this->getTableColumns($table) !== [] || $this->tableAccessService->getTableTitle($table) !== $table;
    }

    /**
     * @param array<mixed, mixed> $record
     * @return SearchRecord
     */
    protected function normalizeRecord(array $record): array
    {
        $normalized = [];
        foreach ($record as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Get the tool schema
     */
    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $schema = [
            'description' => "Search for records across workspace-capable TYPO3 tables using TCA-based searchable fields. "
                . "Uses SQL LIKE queries for pattern matching. Useful when you need to find pages or content that might not be visible in the page tree, "
                . "or for thorough duplicate checking after initial exploration.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'terms' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Search terms to find in record content. Use multiple search terms with synonyms for best results.',
                    ],
                    'termLogic' => [
                        'type' => 'string',
                        'enum' => ['AND', 'OR'],
                        'description' => 'Logic for combining multiple terms: AND (all terms must match) or OR (any term matches). Default: OR',
                        'default' => 'OR',
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: Limit search to a specific workspace-capable table (e.g., "tt_content", "pages")',
                    ],
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Optional: Limit search to records on a specific page',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of records to return per table (default: 50)',
                    ],
                ],
                'required' => ['terms'],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (\count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to filter search results (e.g., "de", "fr"). When specified, searches only in content for that language.',
                'enum' => $availableLanguages,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'idempotentHint' => true,
        ];

        return $schema;
    }

    /**
     * Get language recommendations based on site configuration
     */
    protected function getLanguageRecommendations(): string
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $siteFinder->getAllSites();

            $languages = [];
            foreach ($sites as $site) {
                foreach ($site->getAllLanguages() as $language) {
                    $languages[$language->getLanguageId()] = [
                        'title' => $language->getTitle(),
                        'locale' => $language->getLocale()->getName(),
                        'iso' => $language->getLocale()->getLanguageCode(),
                    ];
                }
            }

            if (\count($languages) <= 1) {
                return "LANGUAGES:\n• Single language site detected\n\n";
            }

            $recommendation = "MULTILINGUAL SEARCH:\n";
            $recommendation .= "• This site has " . \count($languages) . " languages configured:\n";

            foreach ($languages as $langId => $langInfo) {
                $recommendation .= "  - {$langInfo['title']} ({$langInfo['iso']})";
                if ($langId === 0) {
                    $recommendation .= " [Default]";
                }
                $recommendation .= "\n";
            }

            $recommendation .= "• Search terms should match the content language\n";
            $recommendation .= "• Try terms in different languages for broader results\n";
            $recommendation .= "• Language-specific content may be on different pages\n\n";

            return $recommendation;

        } catch (Throwable $e) {
            // Log the error but return a helpful message
            $this->logException($e, 'language detection');
            return "LANGUAGES:\n• Could not detect language configuration\n• Try search terms in your site's primary language\n\n";
        }
    }

    /**
     * Execute the tool logic
     */
    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        // Validate all parameters first
        $this->validateParameters($params);

        // Extract parameters
        $terms = \is_array($params['terms'] ?? null) ? $params['terms'] : [];
        $termLogic = strtoupper(\is_string($params['termLogic'] ?? null) ? $params['termLogic'] : 'OR');
        $table = trim(\is_string($params['table'] ?? null) ? $params['table'] : '');
        $pageId = isset($params['pageId']) && is_numeric($params['pageId']) ? (int) $params['pageId'] : null;
        $limit = 50;

        // Handle language parameter
        $languageId = null;
        if (isset($params['language']) && \is_string($params['language'])) {
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new ValidationException(['Unknown language code: ' . $params['language']]);
            }
        }

        // Get normalized search terms
        $searchTerms = $this->validateAndNormalizeSearchTerms($terms);

        // Get search results
        $searchResults = $this->performSearch($searchTerms, $termLogic, $table, $pageId, $limit, $languageId);

        // Format results
        $formattedResults = $this->formatSearchResults($searchResults, $searchTerms, $termLogic, $languageId);

        return $this->createSuccessResult($formattedResults);
    }

    /**
     * Validate all parameters
     */
    /**
     * @param array<string, mixed> $params
     */
    protected function validateParameters(array $params): void
    {
        $errors = [];

        // Validate terms
        if (!isset($params['terms']) || !\is_array($params['terms'])) {
            $errors[] = 'Parameter "terms" must be an array of strings';
        } elseif (empty($params['terms'])) {
            $errors[] = 'At least one search term is required in the "terms" array';
        }

        // Validate term logic
        if (isset($params['termLogic'])) {
            $termLogic = strtoupper(\is_string($params['termLogic']) ? $params['termLogic'] : '');
            if (!\in_array($termLogic, ['AND', 'OR'])) {
                $errors[] = 'termLogic must be either "AND" or "OR"';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Validate and normalize search terms
     */
    /**
     * @param array<mixed> $terms
     * @return list<string>
     */
    protected function validateAndNormalizeSearchTerms(array $terms): array
    {
        $searchTerms = [];
        $errors = [];

        foreach ($terms as $term) {
            if (!\is_string($term)) {
                $errors[] = 'All terms must be strings';
                continue;
            }
            $trimmedTerm = trim($term);
            if (!empty($trimmedTerm)) {
                $searchTerms[] = $trimmedTerm;
            }
        }

        // Validate we have at least one term
        if (empty($searchTerms)) {
            $errors[] = 'At least one non-empty search term is required';
        }

        // Validate term lengths
        foreach ($searchTerms as $term) {
            if (\strlen($term) < 2) {
                $errors[] = 'All search terms must be at least 2 characters long. Term "' . $term . '" is too short';
            }
            if (\strlen($term) > 100) {
                $errors[] = 'Search terms cannot exceed 100 characters. Term "' . substr($term, 0, 20) . '..." is too long';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $searchTerms;
    }

    /**
     * Perform search across tables (including inline relations)
     */
    /**
     * @param list<string> $searchTerms
     * @return array<string, SearchResult|list<SearchRecord>|array<string, mixed>>
     */
    protected function performSearch(array $searchTerms, string $termLogic, string $table, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $searchResults = [];
        $inlineTableMetadata = [];

        // Get primary tables to search (accessible tables with searchable fields)
        $primaryTables = $this->getTablesToSearch($table);

        // Discover related tables referenced by primary tables (inline/select relations)
        $inlineTableInfo = $this->getInlineRelatedHiddenTables($primaryTables);

        // Create lookup for inline table metadata
        foreach ($inlineTableInfo as $inlineInfo) {
            $inlineTableMetadata[$inlineInfo['table']] = $inlineInfo;
        }

        // Combine primary tables with inline tables for searching
        $allTablesToSearch = array_merge($primaryTables, array_column($inlineTableInfo, 'table'));

        foreach ($allTablesToSearch as $tableName) {
            $searchableFields = $this->getSearchableFields($tableName);

            if (empty($searchableFields)) {
                continue;
            }

            $results = $this->searchInTable($tableName, $searchTerms, $termLogic, $searchableFields, $pageId, $limit, $languageId);

            if (!empty($results) && !empty($results['records'])) {
                // Mark inline table results for attribution
                if (isset($inlineTableMetadata[$tableName])) {
                    $results['_inline_metadata'] = $inlineTableMetadata[$tableName];
                }

                $searchResults[$tableName] = $results;
            }
        }

        // Attribute inline results to parent records
        $attributedResults = $this->attributeInlineResultsToParents($searchResults, $inlineTableMetadata);

        return $attributedResults;
    }

    /**
     * Attribute inline table results to their parent records
     */
    /**
     * @param array<string, SearchResult|list<SearchRecord>|array<string, mixed>> $searchResults
     * @param array<string, InlineTableMetadata> $inlineTableMetadata
     * @return array<string, SearchResult|list<SearchRecord>|array<string, mixed>>
     */
    protected function attributeInlineResultsToParents(array $searchResults, array $inlineTableMetadata): array
    {
        $attributedResults = [];
        $parentRecordCache = [];

        foreach ($searchResults as $tableName => $tableResults) {
            // Check if this is an inline table result
            if (isset($tableResults['_inline_metadata'])) {
                $inlineMetadata = $tableResults['_inline_metadata'];
                if (!\is_array($inlineMetadata)) {
                    continue;
                }
                $parentTable = \is_string($inlineMetadata['parent_table'] ?? null) ? $inlineMetadata['parent_table'] : '';
                $foreignField = \is_string($inlineMetadata['foreign_field'] ?? null) ? $inlineMetadata['foreign_field'] : '';
                $parentField = \is_string($inlineMetadata['parent_field'] ?? null) ? $inlineMetadata['parent_field'] : '';
                $relationType = \is_string($inlineMetadata['relation_type'] ?? null) ? $inlineMetadata['relation_type'] : 'inline';
                if ($parentTable === '' || $parentField === '') {
                    continue;
                }

                // Process each inline record and find its parent(s)
                // Note: tableResults is the structure returned by searchInTable which includes metadata
                $inlineRecords = $tableResults['records'] ?? [];
                if (!\is_array($inlineRecords)) {
                    continue;
                }
                foreach ($inlineRecords as $inlineRecord) {
                    if (!\is_array($inlineRecord)) {
                        continue;
                    }
                    $inlineRecord = $this->normalizeRecord($inlineRecord);

                    $parentRecords = $this->findParentRecordsForInlineRecord(
                        $inlineRecord,
                        $tableName,
                        $parentTable,
                        $foreignField,
                        $parentField,
                        $relationType,
                    );

                    // Add the inline match info to each parent record
                    foreach ($parentRecords as $parentRecord) {
                        $parentUid = is_numeric($parentRecord['uid'] ?? null) ? (int) $parentRecord['uid'] : 0;
                        if ($parentUid <= 0) {
                            continue;
                        }
                        $parentKey = $parentTable . '_' . $parentUid;

                        // Cache parent record
                        if (!isset($parentRecordCache[$parentKey])) {
                            $parentRecordCache[$parentKey] = $parentRecord;
                            $parentRecordCache[$parentKey]['_inline_matches'] = [];
                        }

                        // Add inline match information
                        $parentRecordCache[$parentKey]['_inline_matches'][] = [
                            'table' => $tableName,
                            'record' => $inlineRecord,
                            'field' => $parentField,
                            'type' => $relationType,
                        ];
                    }
                }

                // Don't include the inline table directly in results
                continue;
            }

            // Include regular (non-inline) table results as-is
            $attributedResults[$tableName] = $tableResults;
        }

        // Add parent records that had inline matches
        foreach ($parentRecordCache as $parentKey => $parentRecord) {
            $parentTable = explode('_', $parentKey)[0];

            if (!isset($attributedResults[$parentTable])) {
                $attributedResults[$parentTable] = [];
            }

            // Remove the cached key prefix and add to results
            unset($parentRecord['_parent_key']);
            $attributedResults[$parentTable][] = $parentRecord;
        }

        return $attributedResults;
    }

    /**
     * Find parent records for an inline record
     */
    /**
     * @param SearchRecord $inlineRecord
     * @return list<SearchRecord>
     */
    protected function findParentRecordsForInlineRecord(
        array $inlineRecord,
        string $inlineTable,
        string $parentTable,
        string $foreignField,
        string $parentField,
        string $relationType = 'inline',
    ): array {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($parentTable);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()));

        $queryBuilder->select('*')->from($parentTable);

        if ($relationType === 'inline' && !empty($foreignField)) {
            // For inline relations, use the foreign_field to find parent
            $parentUid = $inlineRecord[$foreignField] ?? null;
            if ($parentUid) {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER)),
                );
            } else {
                return [];
            }
        } elseif ($relationType === 'select') {
            // For select relations, find records that reference this inline record
            $inlineUid = $inlineRecord['uid'] ?? null;
            if (\is_scalar($inlineUid) && (string) $inlineUid !== '') {
                $queryBuilder->where(
                    $queryBuilder->expr()->like(
                        $parentField,
                        $queryBuilder->createNamedParameter('%' . (string) $inlineUid . '%'),
                    ),
                );
            } else {
                return [];
            }
        } else {
            return [];
        }

        try {
            $parentRecords = $queryBuilder->executeQuery()->fetchAllAssociative();

            // Enhance with page information
            return $this->enhanceRecordsWithPageInfo($parentRecords, $parentTable);
        } catch (Throwable $e) {
            // Log the error but continue without parent records
            $this->logException($e, 'finding parent records');
            return [];
        }
    }

    /**
     * Get tables to search (accessible tables with searchable fields)
     */
    /**
     * @return list<string>
     */
    protected function getTablesToSearch(string $specificTable = ''): array
    {
        if (!empty($specificTable)) {
            // Validate table access using TableAccessService
            try {
                $this->ensureTableAccess($specificTable, 'read');
            } catch (InvalidArgumentException $e) {
                throw new ValidationException(['Cannot search table "' . $specificTable . '": ' . $e->getMessage()]);
            }

            return [$specificTable];
        }

        // Get all readable tables (includes non-workspace-capable tables for read operations)
        $accessibleTables = $this->tableAccessService->getReadableTables();

        // Filter for tables that have searchable fields
        $searchableTables = [];
        foreach ($accessibleTables as $tableName => $accessInfo) {
            // Only include tables that have searchable fields defined in TCA
            if (!empty($this->getSearchableFields($tableName))) {
                $searchableTables[] = $tableName;
            }
        }

        return $searchableTables;
    }

    /**
     * Discover related tables that are referenced by primary tables (including relation tables)
     */
    /**
     * @param list<string> $primaryTables
     * @return list<InlineTableMetadata>
     */
    protected function getInlineRelatedHiddenTables(array $primaryTables): array
    {
        $inlineTables = [];

        foreach ($primaryTables as $primaryTable) {
            $columns = $this->getTableColumns($primaryTable);
            if ($columns === []) {
                continue;
            }

            // Look through all columns for relations
            foreach ($columns as $fieldName => $fieldConfig) {
                $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
                $fieldType = \is_string($fieldOptions['type'] ?? null) ? $fieldOptions['type'] : '';

                // Check for inline fields
                if ($fieldType === 'inline') {
                    $foreignTable = \is_string($fieldOptions['foreign_table'] ?? null) ? $fieldOptions['foreign_table'] : '';

                    if ($foreignTable !== '' && $this->tableExistsInTca($foreignTable)) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->getSearchableFields($foreignTable))) {
                            $inlineTables[$foreignTable] = [
                                'table' => $foreignTable,
                                'parent_table' => $primaryTable,
                                'parent_field' => $fieldName,
                                'foreign_field' => \is_string($fieldOptions['foreign_field'] ?? null) ? $fieldOptions['foreign_field'] : '',
                            ];
                        }
                    }
                }

                // Also check for select fields with foreign_table (like categories)
                if ($fieldType === 'select') {
                    $foreignTable = \is_string($fieldOptions['foreign_table'] ?? null) ? $fieldOptions['foreign_table'] : '';

                    // Skip self-referential relations (like localization fields)
                    if ($foreignTable !== '' && $foreignTable !== $primaryTable && $this->tableExistsInTca($foreignTable)) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->getSearchableFields($foreignTable))) {
                            $inlineTables[$foreignTable] = [
                                'table' => $foreignTable,
                                'parent_table' => $primaryTable,
                                'parent_field' => $fieldName,
                                'foreign_field' => '', // Select relations don't use foreign_field
                                'relation_type' => 'select',
                            ];
                        }
                    }
                }
            }
        }

        return array_values($inlineTables);
    }

    /**
     * Get searchable fields for a table from TCA
     */
    /**
     * @return list<string>
     */
    protected function getSearchableFields(string $table): array
    {
        return $this->tableAccessService->getSearchFields($table);
    }

    /**
     * Validate that searchable fields actually exist in the database table
     */
    /**
     * @param list<string> $searchableFields
     * @return list<string>
     */
    protected function validateSearchableFields(string $table, array $searchableFields): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable($table);

        try {
            // Get the actual columns from the database table
            $schemaManager = $connection->createSchemaManager();
            $tableColumns = $schemaManager->listTableColumns($table);
            $availableColumns = array_keys($tableColumns);

            // Filter searchable fields to only include existing columns
            $validFields = [];
            foreach ($searchableFields as $field) {
                if (\in_array($field, $availableColumns)) {
                    $validFields[] = $field;
                }
            }

            return $validFields;
        } catch (Throwable $e) {
            // Log validation error but continue with original fields
            $this->logException($e, 'validating searchable fields');
            return $searchableFields;
        }
    }

    /**
     * Search in a specific table with multiple terms and AND/OR logic
     */
    /**
     * @param list<string> $searchTerms
     * @param list<string> $searchableFields
     * @return SearchResult|array{}
     */
    protected function searchInTable(string $table, array $searchTerms, string $termLogic, array $searchableFields, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $this->getCurrentWorkspaceId()));

        // Select all fields
        $queryBuilder->select('*')->from($table);

        // Validate searchable fields exist in database
        $validSearchFields = $this->validateSearchableFields($table, $searchableFields);


        if (empty($validSearchFields)) {
            return [];
        }

        // Build search conditions for multiple terms
        $termConditions = [];

        foreach ($searchTerms as $term) {
            // For each term, create conditions across all searchable fields
            $fieldConditions = [];
            foreach ($validSearchFields as $field) {
                $fieldConditions[] = $queryBuilder->expr()->like(
                    $field,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($term) . '%'),
                );
            }

            // Combine field conditions with OR (any field can match this term)
            $termConditions[] = $queryBuilder->expr()->or(...$fieldConditions);
        }

        if (empty($termConditions)) {
            return [];
        }

        // Combine term conditions based on logic (AND/OR)
        if ($termLogic === 'AND') {
            // All terms must match (in any field)
            $queryBuilder->where($queryBuilder->expr()->and(...$termConditions));
        } else {
            // Any term can match (OR logic - default)
            $queryBuilder->where($queryBuilder->expr()->or(...$termConditions));
        }

        // Filter by page ID if specified
        if ($pageId !== null && $this->tableHasPidField($table)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            );
        }

        // Filter by language if specified and table has language support
        if ($languageId !== null && $this->tableHasLanguageSupport($table)) {
            if ($languageId === 0) {
                // Default language: only show records with sys_language_uid = 0 or -1 (all languages)
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER)),
                    ),
                );
            } else {
                // Specific language: show records in that language, default language, or all languages
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER)),
                    ),
                );
            }
        }

        // Apply default sorting
        RecordFormattingUtility::applyDefaultSorting($queryBuilder, $table);

        // Apply limit
        $queryBuilder->setMaxResults($limit);

        // Execute query with error handling
        try {
            $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (Exception $e) {
            throw new DatabaseException('search', $table, $e);
        }

        // Return records in expected structure format
        return [
            'records' => $this->enhanceRecordsWithPageInfo($records, $table, $languageId),
            'total' => \count($records),
            'search_terms' => $searchTerms,
            'term_logic' => $termLogic,
        ];
    }

    /**
     * Check if table has language support
     */
    protected function tableHasLanguageSupport(string $table): bool
    {
        return $this->tableAccessService->getLanguageFieldName($table) !== null;
    }

    /**
     * Enhance records with page information
     */
    /**
     * @param list<SearchRecord> $records
     * @return list<SearchRecord>
     */
    protected function enhanceRecordsWithPageInfo(array $records, string $table, ?int $languageId = null): array
    {
        if (empty($records)) {
            return $records;
        }

        // Process records for workspace transparency
        $processedRecords = [];
        $seenUids = [];

        foreach ($records as $record) {
            // For workspace transparency, replace workspace UID with live UID
            if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
                // This is a workspace version - use the live UID instead
                $record['uid'] = is_numeric($record['t3ver_oid']) ? (int) $record['t3ver_oid'] : $record['uid'];
            } elseif (isset($record['t3ver_state']) && $record['t3ver_state'] == 1) {
                // This is a new placeholder record - its UID is already the "live" UID
                // No change needed
            }

            // De-duplicate records based on UID after processing
            $uid = is_numeric($record['uid'] ?? null) ? (int) $record['uid'] : 0;
            if (!isset($seenUids[$uid])) {
                $processedRecords[] = $record;
                $seenUids[$uid] = true;
            }
        }

        $records = $processedRecords;

        // Get unique page IDs
        $pageIds = [];
        foreach ($records as $record) {
            if (is_numeric($record['pid'] ?? null) && (int) $record['pid'] > 0) {
                $pageIds[] = (int) $record['pid'];
            }
        }

        if (empty($pageIds)) {
            return $records;
        }

        // Get page information
        /** @var list<int> $uniquePageIds */
        $uniquePageIds = array_values(array_unique($pageIds));
        $pageInfo = $this->getPageInfo($uniquePageIds);

        // Enhance records
        foreach ($records as &$record) {
            $pid = is_numeric($record['pid'] ?? null) ? (int) $record['pid'] : 0;
            if (isset($pageInfo[$pid])) {
                $record['_page'] = $pageInfo[$pid];
            }
        }

        return $records;
    }

    /**
     * Get page information for multiple page IDs
     */
    /**
     * @param list<int> $pageIds
     * @return array<int, array<string, mixed>>
     */
    protected function getPageInfo(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()));

        $pages = $queryBuilder->select('uid', 'title', 'slug', 'nav_title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Index by UID
        $pageInfo = [];
        foreach ($pages as $page) {
            $pageUid = is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0;
            if ($pageUid > 0) {
                $pageInfo[$pageUid] = $page;
            }
        }

        return $pageInfo;
    }

    /**
     * Format search results
     */
    /**
     * @param array<string, SearchResult|list<SearchRecord>|array<string, mixed>> $searchResults
     * @param list<string> $searchTerms
     */
    protected function formatSearchResults(array $searchResults, array $searchTerms, string $termLogic, ?int $languageId = null): string
    {

        $result = "SEARCH RESULTS\n";
        $result .= "==============\n";

        // Display search terms
        if (\count($searchTerms) === 1) {
            $result .= "Query: \"" . $searchTerms[0] . "\"\n";
        } else {
            $result .= "Search Terms: [" . implode(', ', array_map(fn($t) => '"' . $t . '"', $searchTerms)) . "]\n";
            $result .= "Logic: " . $termLogic . " (records must match "
                      . ($termLogic === 'AND' ? 'ALL terms' : 'ANY term') . ")\n";
        }

        if ($languageId !== null) {
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown';
            $result .= "Language Filter: " . strtoupper($isoCode) . " (ID: $languageId)\n";
        }

        $result .= "\n";

        $totalResults = 0;
        foreach ($searchResults as $tableResults) {
            if (\is_array($tableResults) && isset($tableResults['records']) && \is_array($tableResults['records'])) {
                $totalResults += \count($tableResults['records']);
            } else {
                $totalResults += \is_array($tableResults) ? \count($tableResults) : 0;
            }
        }

        $result .= "Total Results: $totalResults\n";
        $result .= "Tables Searched: " . \count($searchResults) . "\n\n";

        // If no results, show no results message
        if ($totalResults === 0) {
            $termsDisplay = \count($searchTerms) === 1 ? '"' . $searchTerms[0] . '"' : '[' . implode(', ', array_map(fn($t) => '"' . $t . '"', $searchTerms)) . ']';
            $result .= "No results found for search terms: $termsDisplay\n";
        } else {
            // Format results by table
            foreach ($searchResults as $table => $records) {
                $result .= $this->formatTableResults($table, $records, $searchTerms, $languageId);
            }
        }

        return $result;
    }

    /**
     * Format results for a specific table
     */
    /**
     * @param SearchResult|list<SearchRecord>|array<string, mixed> $tableData
     * @param list<string> $searchTerms
     */
    protected function formatTableResults(string $table, array $tableData, array $searchTerms, ?int $languageId = null): string
    {
        $tableLabel = RecordFormattingUtility::getTableLabel($table);
        $result = "TABLE: $tableLabel ($table)\n";
        $result .= str_repeat('-', \strlen("TABLE: $tableLabel ($table)")) . "\n";

        // Handle both searchInTable result structure and attributed results array
        $records = [];
        if (isset($tableData['records']) && \is_array($tableData['records'])) {
            // This is a searchInTable result structure
            $records = $tableData['records'];
        } elseif ($tableData !== []) {
            // This is a direct array of records (from attributed results)
            $records = $tableData;
        }

        $result .= "Found " . \count($records) . " record(s)\n\n";

        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }
            $record = $this->normalizeRecord($record);
            $result .= $this->formatRecord($table, $record, $searchTerms, $languageId);
        }

        $result .= "\n";
        return $result;
    }

    /**
     * Format a single record
     */
    /**
     * @param SearchRecord $record
     * @param list<string> $searchTerms
     */
    protected function formatRecord(string $table, array $record, array $searchTerms, ?int $languageId = null): string
    {
        $title = RecordFormattingUtility::getRecordTitle($table, $record);
        $uid = \is_scalar($record['uid'] ?? null) ? (string) $record['uid'] : 'unknown';

        $result = "• [UID: $uid] $title\n";

        // Add page information if available
        if (isset($record['_page']) && \is_array($record['_page'])) {
            $pageInfo = $record['_page'];
            $pageTitle = \is_scalar($pageInfo['title'] ?? null) ? (string) $pageInfo['title'] : 'Untitled Page';
            $pageUid = \is_scalar($pageInfo['uid'] ?? null) ? (string) $pageInfo['uid'] : 'unknown';
            $result .= "  📍 Page: $pageTitle [UID: $pageUid]\n";
        }

        // Add record type information
        if ($table === 'tt_content' && isset($record['CType'])) {
            $cType = \is_scalar($record['CType']) ? (string) $record['CType'] : 'unknown';
            $cTypeLabel = RecordFormattingUtility::getContentTypeLabel($cType);
            $result .= "  🎯 Type: $cTypeLabel ($cType)\n";
        }

        // Add language information if table has language support
        if ($this->tableHasLanguageSupport($table) && isset($record['sys_language_uid'])) {
            $recordLangId = is_numeric($record['sys_language_uid']) ? (int) $record['sys_language_uid'] : 0;
            if ($recordLangId > 0) {
                $langCode = $this->languageService->getIsoCodeFromUid($recordLangId) ?? 'unknown';
                $result .= "  🌐 Language: " . strtoupper($langCode) . "\n";
            } elseif ($recordLangId === -1) {
                $result .= "  🌐 Language: All\n";
            }
        }

        // Show preview of matching content
        $preview = $this->getMatchingContentPreview($table, $record, $searchTerms);
        if (!empty($preview)) {
            $result .= "  💬 Preview: $preview\n";
        }

        // Show inline matches if any
        if (isset($record['_inline_matches']) && \is_array($record['_inline_matches'])) {
            foreach ($record['_inline_matches'] as $inlineMatch) {
                if (!\is_array($inlineMatch)) {
                    continue;
                }
                $inlineTable = $inlineMatch['table'];
                $inlineRecord = $inlineMatch['record'];
                $inlineField = $inlineMatch['field'];
                $inlineType = $inlineMatch['type'];
                if (!\is_string($inlineTable) || !\is_array($inlineRecord) || !\is_string($inlineField) || !\is_string($inlineType)) {
                    continue;
                }
                $inlineRecord = $this->normalizeRecord($inlineRecord);

                $inlineTitle = RecordFormattingUtility::getRecordTitle($inlineTable, $inlineRecord);
                $inlineTableLabel = RecordFormattingUtility::getTableLabel($inlineTable);

                // Show different icons based on relation type
                $icon = $inlineType === 'select' ? '🏷️' : '📎';

                $result .= "  $icon Contains: $inlineTitle [$inlineTableLabel via $inlineField]\n";

                // Show preview of the inline match
                $inlinePreview = $this->getMatchingContentPreview($inlineTable, $inlineRecord, $searchTerms);
                if (!empty($inlinePreview)) {
                    $result .= "    💬 Match: $inlinePreview\n";
                }
            }
        }

        $result .= "\n";
        return $result;
    }

    /**
     * Get preview of content that matches the search query
     */
    /**
     * @param SearchRecord $record
     * @param list<string> $searchTerms
     */
    protected function getMatchingContentPreview(string $table, array $record, array $searchTerms): string
    {
        $searchableFields = $this->getSearchableFields($table);
        $previews = [];

        foreach ($searchableFields as $field) {
            if (!isset($record[$field]) || empty($record[$field])) {
                continue;
            }

            if (!\is_scalar($record[$field])) {
                continue;
            }
            $content = (string) $record[$field];

            // Remove HTML tags for preview
            $content = strip_tags($content);

            // Check if this field contains any of the search terms
            foreach ($searchTerms as $term) {
                if (stripos($content, $term) !== false) {
                    // Extract a snippet around the match
                    $snippet = RecordFormattingUtility::extractSnippet($content, $term);
                    if (!empty($snippet)) {
                        $previews[] = $snippet;
                        break; // Found a match in this field, move to next field
                    }
                }
            }
        }

        if (empty($previews)) {
            return '';
        }

        return implode(' ... ', \array_slice($previews, 0, 2)); // Limit to 2 snippets
    }

    /**
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
    {
        return RecordFormattingUtility::tableHasPidField($table);
    }

}
