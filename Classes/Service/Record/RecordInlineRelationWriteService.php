<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\FileMetadataIndexService;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Extracts, validates, and maps inline/file relations for WriteTableTool DataHandler calls.
 *
 * @phpstan-type InlineRelation array{config: array<string, mixed>, value: mixed}
 * @phpstan-type InlineRelations array<string, InlineRelation>
 */
final readonly class RecordInlineRelationWriteService
{
    public function __construct(
        private TableAccessService $tableAccessService,
        private ConnectionPool $connectionPool,
        private FileMetadataIndexService $fileMetadataIndexService,
        private RecordDataWriteConverter $dataWriteConverter,
    ) {}
public function extractFromData(string $table, array &$data): array
    {
        $inlineRelations = [];

        if (!isset($GLOBALS['TCA'][$table]['columns'])) {
            return $inlineRelations;
        }

        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldType = is_array($fieldConfig) ? ($fieldConfig['config']['type'] ?? '') : '';
            // Handle both inline and file fields (file is inline to sys_file_reference)
            if ($fieldConfig && in_array($fieldType, ['inline', 'file'], true)) {
                $config = $fieldConfig['config'];
                // For file fields, ensure foreign_table defaults to sys_file_reference
                if ($fieldType === 'file' && empty($config['foreign_table'])) {
                    $config['foreign_table'] = 'sys_file_reference';
                }
                if ($fieldType === 'file' && empty($config['foreign_field'])) {
                    $config['foreign_field'] = 'uid_foreign';
                }
                $inlineRelations[$fieldName] = [
                    'config' => $config,
                    'value' => $value,
                ];
                // Remove from data array as we'll process it via buildInlineDataMap
                unset($data[$fieldName]);
            }
        }

        return $inlineRelations;
    }


public function buildDataMap(
        array &$dataMap,
        string $parentTable,
        $parentId,
        int $pid,
        array $inlineRelations
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $value = $relationData['value'];
            $foreignTable = $config['foreign_table'] ?? '';
            $foreignField = $config['foreign_field'] ?? '';

            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }

            $isFileReference = ($foreignTable === 'sys_file_reference');
            $isEmbeddedTable = $this->tableAccessService->isEmbeddedChildTable($foreignTable);

            // Build the list of child identifiers (NEW keys for new records, UIDs for existing)
            $childIdentifiers = [];

            // Allowed sys_file_reference metadata fields that callers may set per attachment.
            $allowedFileRefMetaFields = ['title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'showinpreview'];

            foreach ($value as $index => $item) {
                if (is_string($parentId) && $isEmbeddedTable && is_array($item) && isset($item['uid']) && is_numeric($item['uid']) && (int)$item['uid'] > 0) {
                    throw new ValidationException([
                        sprintf(
                            'Inline relation %s.%s at index %d references uid %d which does not belong to the current parent record. Embedded relations cannot be moved between parents.',
                            $foreignTable,
                            $foreignField,
                            $index,
                            (int)$item['uid']
                        ),
                    ]);
                }

                if ($isFileReference) {
                    // File field accepts:
                    //  1) plain sys_file UID (shorthand): 5
                    //  2) object with uid_local + optional metadata: {"uid_local": 5, "title": "...", "alternative": "..."}
                    //  3) reference to existing sys_file_reference UID with optional updates: {"uid": 12, "title": "..."}
                    if (is_numeric($item) && (int)$item > 0) {
                        $this->fileMetadataIndexService->ensureImageMetadataForFileUid((int)$item);

                        $childNewId = 'NEW' . bin2hex(random_bytes(8));
                        $refData = [
                            'uid_local' => (int)$item,
                            'pid' => $pid,
                            'tablenames' => $parentTable,
                            'fieldname' => $fieldName,
                            'table_local' => 'sys_file',
                        ];
                        if (isset($config['foreign_sortby'])) {
                            $refData[$config['foreign_sortby']] = ($index + 1) * 256;
                        }
                        $dataMap[$foreignTable][$childNewId] = $refData;
                        $childIdentifiers[] = $childNewId;
                        continue;
                    }

                    if (is_array($item) && isset($item['uid_local']) && is_numeric($item['uid_local']) && (int)$item['uid_local'] > 0) {
                        $this->fileMetadataIndexService->ensureImageMetadataForFileUid((int)$item['uid_local']);

                        $childNewId = 'NEW' . bin2hex(random_bytes(8));
                        $refData = [
                            'uid_local' => (int)$item['uid_local'],
                            'pid' => $pid,
                            'tablenames' => $parentTable,
                            'fieldname' => $fieldName,
                            'table_local' => 'sys_file',
                        ];
                        foreach ($allowedFileRefMetaFields as $metaField) {
                            if (!array_key_exists($metaField, $item)) {
                                continue;
                            }
                            $metaValue = $item[$metaField];
                            $metaFieldConfig = $this->tableAccessService->getFieldConfig($foreignTable, $metaField);
                            if (($metaFieldConfig['config']['type'] ?? '') === 'imageManipulation' && is_array($metaValue)) {
                                $refData[$metaField] = $metaValue;
                                continue;
                            }
                            if (is_string($metaValue) || is_numeric($metaValue) || is_bool($metaValue) || $metaValue === null) {
                                $refData[$metaField] = is_bool($metaValue) ? (int)$metaValue : $metaValue;
                            }
                        }
                        if (isset($config['foreign_sortby'])) {
                            $refData[$config['foreign_sortby']] = ($index + 1) * 256;
                        }
                        $refData = $this->dataWriteConverter->convert($foreignTable, $refData);
                        $dataMap[$foreignTable][$childNewId] = $refData;
                        $childIdentifiers[] = $childNewId;
                        continue;
                    }

                    if (is_array($item) && isset($item['uid']) && is_numeric($item['uid']) && (int)$item['uid'] > 0) {
                        $existingUid = (int)$item['uid'];
                        unset($item['uid'], $item[$foreignField]);
                        if (isset($config['foreign_sortby'])) {
                            $item[$config['foreign_sortby']] = ($index + 1) * 256;
                        }
                        $item = $this->dataWriteConverter->convert($foreignTable, $item);
                        if (!empty($item)) {
                            $dataMap[$foreignTable][$existingUid] = $item;
                        }
                        $childIdentifiers[] = $existingUid;
                        continue;
                    }
                    // Invalid items were already caught by validateInlineRelationData
                    continue;
                }

                if (is_array($item) && isset($item['uid']) && is_numeric($item['uid']) && (int)$item['uid'] > 0) {
                    // Existing record reference via {"uid": N, ...} — keep or update
                    $existingUid = (int)$item['uid'];
                    unset($item['uid'], $item[$foreignField]);
                    if (isset($config['foreign_sortby'])) {
                        $item[$config['foreign_sortby']] = ($index + 1) * 256;
                    }
                    $item = $this->dataWriteConverter->convert($foreignTable, $item);

                    // If additional fields provided, add as update to dataMap
                    if (!empty($item)) {
                        $dataMap[$foreignTable][$existingUid] = $item;
                    }

                    $childIdentifiers[] = $existingUid;
                } elseif (is_array($item)) {
                    // Embedded record data — create a new child record
                    $childNewId = 'NEW' . bin2hex(random_bytes(8));

                    // Remove foreign field — DataHandler sets it via inline parent context
                    unset($item[$foreignField]);
                    $item['pid'] = $pid;
                    if (isset($config['foreign_sortby'])) {
                        $item[$config['foreign_sortby']] = ($index + 1) * 256;
                    }
                    $item = $this->dataWriteConverter->convert($foreignTable, $item);

                    $dataMap[$foreignTable][$childNewId] = $item;
                    $childIdentifiers[] = $childNewId;
                } elseif (is_numeric($item) && (int)$item > 0) {
                    // Plain UID — reference an existing independent inline child directly
                    $childIdentifiers[] = (int)$item;
                }
                // Invalid items were already caught by validateInlineRelationData
            }

            // Set the inline field on the parent to the CSV of child identifiers.
            // DataHandler resolves NEW keys, sets foreign_field values, and handles
            // workspace versioning. For creates, this is sufficient. For updates,
            // syncInlineRelations() must also be called to delete absent children
            // (DataHandler's raw dataMap does not handle relation sync automatically).
            $dataMap[$parentTable][$parentId][$fieldName] = implode(',', $childIdentifiers);
        }
    }


public function syncRelations(
        array &$dataMap,
        array &$cmdMap,
        string $parentTable,
        int $parentLiveUid,
        array $inlineRelations
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $value = is_array($relationData['value'] ?? null) ? $relationData['value'] : [];
            $foreignTable = $config['foreign_table'] ?? '';
            $foreignField = $config['foreign_field'] ?? '';

            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }

            $newChildUids = [];
            foreach ($dataMap[$parentTable] as $parentData) {
                if (!isset($parentData[$fieldName])) {
                    continue;
                }
                $csv = (string)$parentData[$fieldName];
                if ($csv === '') {
                    continue;
                }
                foreach (explode(',', $csv) as $identifier) {
                    if (is_numeric($identifier)) {
                        $newChildUids[] = (int)$identifier;
                    }
                }
            }
            $newChildUids = array_values(array_unique($newChildUids));

            $foreignMatchFields = $config['foreign_match_fields'] ?? [];
            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable($foreignTable);
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction());

            $existingChildren = $queryBuilder
                ->select('uid')
                ->from($foreignTable)
                ->where(
                    $queryBuilder->expr()->eq(
                        $foreignField,
                        $queryBuilder->createNamedParameter($parentLiveUid, ParameterType::INTEGER)
                    ),
                    // Only live records (not workspace overlays which have t3ver_oid > 0)
                    $queryBuilder->expr()->eq('t3ver_oid', 0)
                );

            foreach ($foreignMatchFields as $matchField => $matchValue) {
                if (!is_string($matchField) || $matchField === '') {
                    continue;
                }
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        $matchField,
                        $queryBuilder->createNamedParameter($matchValue)
                    )
                );
            }

            $existingChildren = $queryBuilder->executeQuery()->fetchAllAssociative();

            $isEmbeddedTable = $this->tableAccessService->isEmbeddedChildTable($foreignTable);
            $existingChildUids = [];

            foreach ($existingChildren as $existingChild) {
                $childUid = $existingChild['uid'] ?? null;
                if (is_numeric($childUid)) {
                    $existingChildUids[] = (int)$childUid;
                }
            }

            if ($isEmbeddedTable) {
                foreach ($value as $index => $item) {
                    if (!is_array($item) || !isset($item['uid']) || !is_numeric($item['uid']) || (int)$item['uid'] <= 0) {
                        continue;
                    }
                    $childUid = (int)$item['uid'];
                    if (!in_array($childUid, $existingChildUids, true)) {
                        throw new ValidationException([
                            sprintf(
                                'Inline relation %s.%s at index %d references uid %d which does not belong to the current parent record. Embedded relations cannot be moved between parents.',
                                $foreignTable,
                                $foreignField,
                                $index,
                                $childUid
                            ),
                        ]);
                    }
                }
            }

            foreach ($existingChildren as $existingChild) {
                $childUid = $existingChild['uid'] ?? null;
                if (!is_numeric($childUid)) {
                    continue;
                }
                $childLiveUid = (int)$childUid;
                if (!in_array($childLiveUid, $newChildUids, true)) {
                    if ($isEmbeddedTable) {
                        // Embedded (hideTable) children: delete via DataHandler cmdMap.
                        // DataHandler handles workspace versioning (creates delete placeholder).
                        $cmdMap[$foreignTable][$childLiveUid]['delete'] = 1;
                    } else {
                        // Independent children: clear foreign_field via DataHandler dataMap.
                        // DataHandler handles workspace versioning (creates workspace overlay).
                        $dataMap[$foreignTable][$childLiveUid] = [$foreignField => 0];
                    }
                }
            }
        }
    }


public function validateField(array $fieldConfig, $value): ?string
    {
        // Check if value is an array
        if (!is_array($value)) {
            return 'Inline relation field must be an array of UIDs or record data';
        }

        // Get foreign table
        $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';
        if (empty($foreignTable)) {
            return 'Invalid inline relation configuration: missing foreign_table';
        }

        $isFileReference = ($foreignTable === 'sys_file_reference');

        // Validate each item - accept both record data arrays and UIDs
        foreach ($value as $index => $item) {
            if ($isFileReference) {
                // File references accept plain sys_file UIDs, new reference data,
                // or an existing sys_file_reference UID to patch/keep.
                if (is_numeric($item) && (int)$item > 0) {
                    continue;
                }
                if (is_array($item)) {
                    $hasExistingReferenceUid = isset($item['uid']) && is_numeric($item['uid']) && (int)$item['uid'] > 0;
                    $hasFileUid = isset($item['uid_local']) && is_numeric($item['uid_local']) && (int)$item['uid_local'] > 0;
                    if (!$hasExistingReferenceUid && !$hasFileUid) {
                        return 'File reference at index ' . $index . ' must contain uid_local (sys_file UID) or uid (existing sys_file_reference UID)';
                    }
                    continue;
                }
                return 'File reference at index ' . $index . ' must be a sys_file UID or an object with uid_local/uid';
            }
            if (is_array($item)) {
                // Record data arrays for embedded inline relations
                if (empty($item)) {
                    return 'Embedded inline relation record at index ' . $index . ' is empty';
                }
            } elseif (is_numeric($item) && $item > 0) {
                // UIDs for independent inline relations
                continue;
            } else {
                return 'Inline relation at index ' . $index . ' must be a record data array or a positive integer UID';
            }
        }

        return null;
    }

}
