<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Utility\RecordFormattingUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

final readonly class RecordSearchExecutor
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getSearchableFields(string $table): array
    {
        return $this->tableAccessService->getSearchFields($table);
    }

    public function ensureTableAccess(string $table, string $operation = 'read'): void
    {
        $this->tableAccessService->validateTableAccess($table, $operation);
    }

    private function logException(\Throwable $e, string $context): void
    {
        unset($e, $context);
    }
    public function getTablesToSearch(string $specificTable = ''): array
    {
        if (!empty($specificTable)) {
            // Validate table access using TableAccessService
            try {
                $this->ensureTableAccess($specificTable, 'read');
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException(['Cannot search table "' . $specificTable . '": ' . $e->getMessage()]);
            }

            return [$specificTable];
        }

        // Use the same access set as ReadTableTool so search never surfaces
        // records the caller could not read via ReadTableTool. $includeReadOnly
        // is true so read-only (but accessible) tables remain searchable.
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);

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

    public function validateSearchableFields(string $table, array $searchableFields): array
    {
        $connectionPool = $this->connectionPool;
        $connection = $connectionPool->getConnectionForTable($table);

        try {
            // Get the actual columns from the database table
            $schemaManager = $connection->createSchemaManager();
            $tableColumns = $schemaManager->listTableColumns($table);
            $availableColumns = array_keys($tableColumns);

            // Filter searchable fields to only include existing columns
            $validFields = [];
            foreach ($searchableFields as $field) {
                if (in_array($field, $availableColumns)) {
                    $validFields[] = $field;
                }
            }

            return $validFields;
        } catch (\Throwable $e) {
            // Log validation error but continue with original fields
            $this->logException($e, 'validating searchable fields');
            return $searchableFields;
        }
    }

    public function searchInTable(string $table, array $searchTerms, string $termLogic, array $searchableFields, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($GLOBALS['BE_USER']->workspace ?? 0))
            ->add(new WorkspaceDeletePlaceholderRestriction($GLOBALS['BE_USER']->workspace ?? 0));

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
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($term) . '%')
                );
            }

            // Combine field conditions with OR (any field can match this term)
            if (!empty($fieldConditions)) {
                $termConditions[] = $queryBuilder->expr()->or(...$fieldConditions);
            }
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
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER))
            );
        }

        // Filter by language if specified and table has language support
        if ($languageId !== null && $this->tableHasLanguageSupport($table)) {
            if ($languageId === 0) {
                // Default language: only show records with sys_language_uid = 0 or -1 (all languages)
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER))
                    )
                );
            } else {
                // Specific language: show records in that language, default language, or all languages
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER))
                    )
                );
            }
        }

        // Apply default sorting
        RecordFormattingUtility::applyDefaultSorting($queryBuilder, $table);

        // Apply limit
        $queryBuilder->setMaxResults($limit);

        $eventDispatcher = $this->eventDispatcher;
        $eventDispatcher->dispatch(new BeforeRecordReadEvent($table, $queryBuilder, 'select', BeforeRecordReadEvent::SOURCE_SEARCH));

        // Execute query with error handling
        try {
            $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (Exception $e) {
            throw new DatabaseException('search', $table, $e);
        }

        // Return records in expected structure format
        return [
            'records' => $this->enhanceRecordsWithPageInfo($records, $table, $languageId),
            'total' => count($records),
            'search_terms' => $searchTerms,
            'term_logic' => $termLogic,
        ];
    }

    public function tableHasLanguageSupport(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['languageField']);
    }

    /**
     * Enhance records with page information
     */
    public function enhanceRecordsWithPageInfo(array $records, string $table, ?int $languageId = null): array
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
                $record['uid'] = $record['t3ver_oid'];
            } elseif (isset($record['t3ver_state']) && (int)$record['t3ver_state'] === 1) {
                // This is a new placeholder record - its UID is already the "live" UID
                // No change needed
            }

            // De-duplicate records based on UID after processing
            $uid = $record['uid'] ?? 0;
            if (!isset($seenUids[$uid])) {
                $processedRecords[] = $record;
                $seenUids[$uid] = true;
            }
        }

        $records = $processedRecords;

        // Get unique page IDs
        $pageIds = [];
        foreach ($records as $record) {
            if (isset($record['pid']) && $record['pid'] > 0) {
                $pageIds[] = (int)$record['pid'];
            }
        }

        if (empty($pageIds)) {
            return $records;
        }

        // Get page information
        $pageInfo = $this->getPageInfo(array_unique($pageIds));

        // Enhance records
        foreach ($records as &$record) {
            $pid = (int)($record['pid'] ?? 0);
            if (isset($pageInfo[$pid])) {
                $record['_page'] = $pageInfo[$pid];
            }
        }

        return $records;
    }

    /**
     * Get page information for multiple page IDs
     */
    private function getPageInfo(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($GLOBALS['BE_USER']->workspace ?? 0));

        $pages = $queryBuilder->select('uid', 'title', 'slug', 'nav_title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Index by UID
        $pageInfo = [];
        foreach ($pages as $page) {
            $pageInfo[(int)$page['uid']] = $page;
        }

        return $pageInfo;
    }

    public function tableHasPidField(string $table): bool
    {
        return RecordFormattingUtility::tableHasPidField($table);
    }

}
