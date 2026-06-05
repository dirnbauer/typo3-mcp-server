<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\TableAccessService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class InlineSearchAttributionService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private RecordSearchExecutor $searchExecutor,
        private TableAccessService $tableAccessService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    private function logException(\Throwable $e, string $context): void
    {
        // SearchTool logs via AbstractTool; keep attribution resilient without failing search.
        unset($e, $context);
    }
    public function attributeInlineResultsToParents(array $searchResults, array $inlineTableMetadata): array
    {
        $attributedResults = [];
        $parentRecordCache = [];

        foreach ($searchResults as $tableName => $tableResults) {
            // Check if this is an inline table result
            if (isset($tableResults['_inline_metadata'])) {
                $inlineMetadata = $tableResults['_inline_metadata'];
                $parentTable = $inlineMetadata['parent_table'];
                $foreignField = $inlineMetadata['foreign_field'];
                $parentField = $inlineMetadata['parent_field'];
                $relationType = $inlineMetadata['relation_type'] ?? 'inline';

                // Process each inline record and find its parent(s)
                // Note: tableResults is the structure returned by searchInTable which includes metadata
                $inlineRecords = $tableResults['records'] ?? [];
                foreach ($inlineRecords as $inlineRecord) {

                    $parentRecords = $this->findParentRecordsForInlineRecord(
                        $inlineRecord,
                        $tableName,
                        $parentTable,
                        $foreignField,
                        $parentField,
                        $relationType
                    );

                    // Add the inline match info to each parent record
                    foreach ($parentRecords as $parentRecord) {
                        $parentUid = $parentRecord['uid'];
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

    public function findParentRecordsForInlineRecord(
        array $inlineRecord,
        string $inlineTable,
        string $parentTable,
        string $foreignField,
        string $parentField,
        string $relationType = 'inline'
    ): array {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($parentTable);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($GLOBALS['BE_USER']->workspace ?? 0));

        $queryBuilder->select('*')->from($parentTable);

        if ($relationType === 'inline' && !empty($foreignField)) {
            // For inline relations, use the foreign_field to find parent
            $parentUid = $inlineRecord[$foreignField] ?? null;
            if ($parentUid) {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER))
                );
            } else {
                return [];
            }
        } elseif ($relationType === 'select') {
            // For select relations, find records that reference this inline record
            $inlineUid = $inlineRecord['uid'] ?? null;
            if ($inlineUid) {
                $queryBuilder->where(
                    $queryBuilder->expr()->like(
                        $parentField,
                        $queryBuilder->createNamedParameter('%' . $inlineUid . '%')
                    )
                );
            } else {
                return [];
            }
        } else {
            return [];
        }

        try {
            $eventDispatcher = $this->eventDispatcher;
            $eventDispatcher->dispatch(new BeforeRecordReadEvent($parentTable, $queryBuilder, 'select', BeforeRecordReadEvent::SOURCE_SEARCH_PARENT));

            $parentRecords = $queryBuilder->executeQuery()->fetchAllAssociative();

            // Enhance with page information
            return $this->searchExecutor->enhanceRecordsWithPageInfo($parentRecords, $parentTable);
        } catch (\Throwable $e) {
            // Log the error but continue without parent records
            $this->logException($e, 'finding parent records');
            return [];
        }
    }

    public function getInlineRelatedHiddenTables(array $primaryTables): array
    {
        $inlineTables = [];

        foreach ($primaryTables as $primaryTable) {
            if (!isset($GLOBALS['TCA'][$primaryTable]['columns'])) {
                continue;
            }

            // Look through all columns for relations
            foreach ($GLOBALS['TCA'][$primaryTable]['columns'] as $fieldName => $fieldConfig) {
                $fieldType = $fieldConfig['config']['type'] ?? '';

                // Check for inline fields
                if ($fieldType === 'inline') {
                    $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';

                    if (!empty($foreignTable) && isset($GLOBALS['TCA'][$foreignTable])) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->searchExecutor->getSearchableFields($foreignTable))) {
                            $inlineTables[$foreignTable] = [
                                'table' => $foreignTable,
                                'parent_table' => $primaryTable,
                                'parent_field' => $fieldName,
                                'foreign_field' => $fieldConfig['config']['foreign_field'] ?? '',
                            ];
                        }
                    }
                }

                // Also check for select fields with foreign_table (like categories)
                if ($fieldType === 'select') {
                    $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';

                    // Skip self-referential relations (like localization fields)
                    if (!empty($foreignTable) && $foreignTable !== $primaryTable && isset($GLOBALS['TCA'][$foreignTable])) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->searchExecutor->getSearchableFields($foreignTable))) {
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

}
