<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\TableAccessService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class RecordRelationReadService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private RecordFieldReadConverter $fieldReadConverter,
        private RecordReadQueryService $readQueryService,
        private EventDispatcherInterface $eventDispatcher,
    ) {}
    public function includeRelations(array $result, string $table, array $requestedFields = []): array
    {
        if (empty($result['records'])) {
            return $result;
        }

        $tca = $GLOBALS['TCA'][$table] ?? [];
        if (empty($tca['columns'])) {
            return $result;
        }

        // Get all record UIDs
        $recordUids = array_column($result['records'], 'uid');

        // Process each field that might contain relations
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            // Skip relations for fields not in the requested field list
            if (!empty($requestedFields) && !in_array($fieldName, $requestedFields)) {
                continue;
            }

            $fieldType = $fieldConfig['config']['type'] ?? '';

            match ($fieldType) {
                'select', 'category' => $this->includeSelectRelations($result['records'], $fieldName, $fieldConfig, $table),
                'inline', 'file' => $this->includeInlineRelations($result['records'], $fieldName, $fieldConfig, $recordUids),
                default => null,
            };
        }

        return $result;
    }

    public function includeSelectRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        // Check if this is a foreign table relation
        if (!empty($fieldConfig['config']['foreign_table'])) {
            $foreignTable = $fieldConfig['config']['foreign_table'];

            // Skip if the foreign table doesn't exist or isn't accessible
            if (!$this->tableAccessService->canAccessTable($foreignTable)) {
                return;
            }

            // Check if this uses MM relations
            if (!empty($fieldConfig['config']['MM'])) {
                $this->includeMmRelations($records, $fieldName, $fieldConfig, $table);
                return;
            }

            // Regular foreign table relation without MM
            $this->includeRegularRelations($records, $fieldName, $fieldConfig);
            return;
        }

        // Handle static items (options from TCA, not from a foreign table)
        if (!empty($fieldConfig['config']['items'])) {
            $this->includeStaticItems($records, $fieldName, $fieldConfig);
        }
    }

    public function includeMmRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        $mmTable = $fieldConfig['config']['MM'];

        // Get MM values for all records
        foreach ($records as &$record) {
            if (isset($record['uid'])) {
                $mmValues = $this->getMmRelationValues(
                    $mmTable,
                    $table,
                    $record['uid'],
                    $fieldName,
                    $fieldConfig['config']
                );
                $record[$fieldName] = $mmValues;
            }
        }
    }

    public function includeRegularRelations(array &$records, string $fieldName, array $fieldConfig): void
    {
        // Check if this field supports multiple values
        $supportsMultiple = false;
        if (isset($fieldConfig['config']['maxitems']) && $fieldConfig['config']['maxitems'] > 1) {
            $supportsMultiple = true;
        }
        if (isset($fieldConfig['config']['multiple']) && $fieldConfig['config']['multiple']) {
            $supportsMultiple = true;
        }

        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName])) {
                if ($supportsMultiple) {
                    // Multi-select field - convert to array
                    if (empty($record[$fieldName]) || $record[$fieldName] === 0 || $record[$fieldName] === '0') {
                        $record[$fieldName] = [];
                    } elseif (is_int($record[$fieldName])) {
                        $record[$fieldName] = [$record[$fieldName]];
                    } else {
                        $values = GeneralUtility::intExplode(',', (string)$record[$fieldName], true);
                        $record[$fieldName] = $values;
                    }
                } else {
                    // Single-select field - keep as single value
                    if (is_string($record[$fieldName]) && str_contains($record[$fieldName], ',')) {
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

    public function includeStaticItems(array &$records, string $fieldName, array $fieldConfig): void
    {
        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName]) && $record[$fieldName] !== '' && $record[$fieldName] !== null) {
                // Convert to array if it's a multi-select field
                if (!empty($fieldConfig['config']['multiple'])) {
                    $values = GeneralUtility::trimExplode(',', (string)$record[$fieldName], true);
                    $record[$fieldName] = $values;
                }
                // Single select fields remain as single values
            }
        }
    }

    public function includeInlineRelations(array &$records, string $fieldName, array $fieldConfig, array $recordUids): void
    {
        if (empty($fieldConfig['config']['foreign_table'])) {
            return;
        }

        $foreignTable = $fieldConfig['config']['foreign_table'];
        $foreignField = $fieldConfig['config']['foreign_field'] ?? '';

        // Skip if the foreign table isn't accessible or no foreign field
        if (!$this->tableAccessService->canAccessTable($foreignTable) || empty($foreignField)) {
            return;
        }

        // Hidden tables are embedded unless explicitly configured as standalone.
        $isHiddenTable = $this->tableAccessService->isEmbeddedChildTable($foreignTable);

        // Get all related records, filtering by foreign_match_fields if present
        // (e.g., sys_file_reference uses tablenames/fieldname to distinguish which field owns each reference)
        $foreignSortBy = $fieldConfig['config']['foreign_sortby'] ?? '';
        $foreignMatchFields = $fieldConfig['config']['foreign_match_fields'] ?? [];
        $relatedRecords = $this->getInlineRelatedRecords($foreignTable, $foreignField, $recordUids, $foreignSortBy, $foreignMatchFields, $isHiddenTable);

        // Allow listeners to enrich or redact inline children (e.g. attach file metadata)
        if (!empty($relatedRecords)) {
            $eventDispatcher = $this->eventDispatcher;
            $afterEvent = new AfterRecordReadEvent($foreignTable, $relatedRecords, 'inline');
            $eventDispatcher->dispatch($afterEvent);
            $relatedRecords = $afterEvent->getRecords();
        }

        // Group related records by parent record
        $groupedRecords = [];
        foreach ($relatedRecords as $relatedRecord) {
            $parentUid = $relatedRecord[$foreignField] ?? null;
            if ($parentUid !== null) {
                if (!isset($groupedRecords[$parentUid])) {
                    $groupedRecords[$parentUid] = [];
                }
                $groupedRecords[$parentUid][] = $relatedRecord;
            }
        }

        // Add related records to each record
        foreach ($records as &$record) {
            $uid = $record['uid'] ?? null;
            if ($uid !== null) {
                if (isset($groupedRecords[$uid]) && !empty($groupedRecords[$uid])) {
                    if ($isHiddenTable) {
                        // Embed full records for hidden tables (like sys_file_reference).
                        // The foreign field that links each child back to its parent is
                        // kept until grouping is done, then dropped — the parent is
                        // already known by virtue of the embedding.
                        $cleaned = [];
                        foreach ($groupedRecords[$uid] as $child) {
                            unset($child[$foreignField]);
                            $cleaned[] = $child;
                        }
                        $record[$fieldName] = $cleaned;
                    } else {
                        // Return only UIDs for independent tables (like tt_content)
                        $record[$fieldName] = array_column($groupedRecords[$uid], 'uid');
                    }
                } else {
                    // Initialize as empty array if field exists in record but no relations found
                    if (array_key_exists($fieldName, $record)) {
                        $record[$fieldName] = [];
                    }
                }
            }
        }
    }

    public function getInlineRelatedRecords(string $table, string $foreignField, array $parentUids, string $foreignSortBy = '', array $foreignMatchFields = [], bool $embedAsChildren = false): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = $this->connectionPool;

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions including workspace delete placeholders
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->readQueryService->getBackendUserForRelations()->workspace ?? 0));

        // Select all fields
        // Apply default sorting if foreign_sortby is defined

        if (empty($foreignSortBy)) {
            $this->readQueryService->applyDefaultSorting($queryBuilder, $table);
        } else {
            $queryBuilder->orderBy($foreignSortBy, 'ASC')
                ->addOrderBy('uid', 'ASC');
        }

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    $foreignField,
                    $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                )
            );

        foreach ($foreignMatchFields as $field => $value) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    (string)$field,
                    $queryBuilder->createNamedParameter($value)
                )
            );
        }

        // Allow listeners to add restrictions to inline-child lookups too
        $eventDispatcher = $this->eventDispatcher;
        $eventDispatcher->dispatch(new BeforeRecordReadEvent($table, $queryBuilder, 'select', BeforeRecordReadEvent::SOURCE_READ_INLINE));

        $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        $records = $this->readQueryService->applyWorkspaceOverlay($table, $records);

        // Embedded children get a curated default whitelist passed through the
        // standard requestedFields filter. The whitelist is computed per record
        // off the row's own type so children of different sub-types in the same
        // batch each keep their type-specific fields. The foreign field is
        // re-injected below only so grouping by parent UID works; the caller
        // drops it at embedding time.
        $typeField = $embedAsChildren ? $this->tableAccessService->getTypeFieldName($table) : null;

        $processedRecords = [];
        foreach ($records as $record) {
            if ($embedAsChildren) {
                $typeValue = $typeField !== null ? ($record[$typeField] ?? null) : null;
                $recordType = is_scalar($typeValue) ? (string)$typeValue : '';
                $requestedFields = $this->tableAccessService->getEmbeddedRecordFields($table, $foreignField, $recordType);
            } else {
                $requestedFields = [];
            }

            $processed = $this->fieldReadConverter->processRecord($record, $table, $requestedFields);

            // Ensure the foreign field is always included if it exists in the raw record
            if (isset($record[$foreignField]) && !isset($processed[$foreignField])) {
                $processed[$foreignField] = $this->fieldReadConverter->convertFieldValue($table, $foreignField, $record[$foreignField]);
            }

            $processedRecords[] = $processed;
        }

        return $processedRecords;
    }

    public function getMmRelationValues(string $mmTable, string $localTable, int $localUid, string $fieldName, array $fieldConfig): array
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
        $matchFields = $fieldConfig['MM_match_fields'] ?? [];
        foreach ($matchFields as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value)
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
            $values[] = (int)$row[$foreignColumn];
        }

        return $values;
    }

}
