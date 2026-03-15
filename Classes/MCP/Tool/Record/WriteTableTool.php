<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Exception;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use LogicException;
use Mcp\Types\CallToolResult;
use RuntimeException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for writing records to TYPO3 tables
 *
 * @phpstan-type RecordData array<string, mixed>
 * @phpstan-type InlineRelation array{config: array<string, mixed>, value: mixed}
 * @phpstan-type InlineRelations array<string, InlineRelation>
 * @phpstan-type SearchReplaceOperation array{search: string, replace: string, replaceAll?: bool}
 * @phpstan-type SearchReplaceMap array<string, list<SearchReplaceOperation>>
 * @phpstan-type DataMap array<string, array<int|string, array<string, mixed>>>
 */
final class WriteTableTool extends AbstractRecordTool
{
    private const DEFAULT_PAGE_DOKTYPE = 1;

    protected LanguageService $languageService;

    public function __construct()
    {
        parent::__construct();
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new RuntimeException('Backend user context not initialized');
        }

        return $backendUser;
    }

    protected function assignBackendUser(DataHandler $dataHandler): void
    {
        $dataHandler->BE_USER = $this->getBackendUser();
    }

    protected function getCurrentWorkspaceId(): int
    {
        return $this->getBackendUser()->workspace ?? 0;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return RecordData
     */
    protected function normalizeRecordData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected function getNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
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
        // Get all accessible tables for enum (exclude read-only tables for write operations)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(false);
        $tableNames = array_keys($accessibleTables);
        sort($tableNames); // Sort alphabetically for better readability

        return [
            'description' => 'Create, update, translate, or delete records in workspace-capable TYPO3 tables. All changes are made in workspace context and require publishing to become live. Language fields (sys_language_uid) can be provided as ISO codes (e.g., "de", "fr") instead of numeric IDs. '
                . 'Before creating or updating content, always use GetPage to understand the page structure, existing content, and writing style. '
                . 'Check existing content elements with ReadTable to ensure new content fits the page\'s tone and doesn\'t duplicate existing elements. '
                . 'For content creation, verify the appropriate colPos by examining existing content layout. '
                . 'Note: If you encounter plugins (CType=list) that reference non-workspace capable tables, '
                . 'look for record storage folders (doktype=254) where the actual records are stored.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create", "update", "translate", or "delete"',
                        'enum' => ['create', 'update', 'translate', 'delete'],
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name to write records to',
                        'enum' => $tableNames,
                    ],
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Page ID for new records (required for "create" action)',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Record UID (required for "update" and "delete" actions)',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Record data with field names as keys and their values (required for "create", "update", and "translate" actions). '
                            . 'Uses the same field syntax as ReadTable output. Language fields (sys_language_uid) accept ISO codes like "de", "fr" instead of numeric IDs. '
                            . 'Inline relations can be specified as arrays - UIDs for independent tables, record data for embedded tables. '
                            . 'File fields (image, assets, media, etc.) accept an array of sys_file UIDs or objects with uid + metadata: '
                            . '[58, 59] or [{"uid": 58, "title": "My image", "description": "Credit"}]. '
                            . 'Use BrowseFiles to find sys_file UIDs. Supported metadata: title, description, alternative, link, crop, autoplay, showinpreview. '
                            . 'For text fields in update actions, instead of providing the full text, you can provide an array of search-and-replace operations: '
                            . '[{"search": "old text", "replace": "new text"}]. Each operation can optionally include "replaceAll": true. '
                            . 'Operations are applied sequentially. Each search string must match exactly once unless replaceAll is true.',
                        'additionalProperties' => true,
                        'examples' => [
                            ['title' => 'News Title', 'bodytext' => 'News <b>content</b>', 'datetime' => '2024-01-01 10:00:00'],
                            ['header' => 'Content Element Header', 'bodytext' => 'Content <b>text</b>', 'CType' => 'text'],
                            ['CType' => 'textmedia', 'header' => 'With Images', 'bodytext' => 'Text', 'assets' => [58, 59]],
                            ['CType' => 'textmedia', 'header' => 'With Metadata', 'assets' => [['uid' => 58, 'title' => 'Photo', 'description' => 'Credit']]],
                            ['sys_language_uid' => 'de', 'title' => 'German translation'],
                            ['header' => [['search' => 'Welcom', 'replace' => 'Welcome'], ['search' => 'Compnay', 'replace' => 'Company']]],
                        ],
                    ],
                    'position' => [
                        'type' => 'string',
                        'description' => 'Position for new records: "top", "bottom", "after:UID", or "before:UID"',
                        'default' => 'bottom',
                    ],
                ],
                'required' => ['action', 'table'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
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

        // Get parameters
        $action = \is_string($params['action'] ?? null) ? $params['action'] : '';
        $table = \is_string($params['table'] ?? null) ? $params['table'] : '';
        $pid = $this->getNullableInt($params['pid'] ?? null);
        $uid = $this->getNullableInt($params['uid'] ?? null);
        $rawData = $params['data'] ?? [];
        $data = \is_array($rawData) ? $this->normalizeRecordData($rawData) : [];
        $position = \is_string($params['position'] ?? null) ? $params['position'] : 'bottom';

        // Validate parameters
        if (empty($action)) {
            throw new ValidationException(['Action is required (create, update, translate, or delete)']);
        }

        if (empty($table)) {
            throw new ValidationException(['Table name is required']);
        }

        // Validate data parameter type
        if (\in_array($action, ['create', 'update', 'translate'], true) && isset($params['data'])) {
            if (!\is_array($params['data'])) {
                $dataType = \gettype($params['data']);
                throw new ValidationException([
                    "Invalid data parameter: Expected an object/array with field names as keys, but received {$dataType}. "
                    . "The data parameter must be an object like {\"title\": \"My Title\", \"bodytext\": \"Content\"}, "
                    . "not a plain string. Each field name should be a key with its corresponding value.",
                ]);
            }
        }

        // Extract search/replace operations from data (arrays of {search, replace} objects
        // on non-inline fields are treated as search-and-replace operations)
        $searchReplace = $this->extractSearchReplaceFromData($table, $data, $action);

        /**
         * IMPORTANT FEATURE: ISO Code Support for sys_language_uid
         *
         * The WriteTableTool accepts ISO language codes (e.g., 'de', 'fr', 'en') for the
         * sys_language_uid field instead of numeric IDs. This makes it much easier for LLMs
         * to work with multilingual content without needing to know the numeric language IDs.
         *
         * Example:
         *   'sys_language_uid' => 'de'  // Will be converted to numeric ID (e.g., 1)
         *
         * This conversion happens automatically for any table that has a sys_language_uid field.
         * The available ISO codes depend on the site configuration.
         */
        // Convert sys_language_uid from ISO code to UID if present
        if (!empty($data) && isset($data['sys_language_uid']) && \is_string($data['sys_language_uid'])) {
            $languageUid = $this->languageService->getUidFromIsoCode($data['sys_language_uid']);
            if ($languageUid === null) {
                throw new ValidationException(['Unknown language code: ' . $data['sys_language_uid']]);
            }
            $data['sys_language_uid'] = $languageUid;
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, $action === 'delete' ? 'delete' : 'write');

        // Validate action-specific parameters
        switch ($action) {
            case 'create':
                if ($pid === null) {
                    throw new ValidationException(['Page ID (pid) is required for create action']);
                }

                if (empty($data)) {
                    throw new ValidationException(['Data is required for create action']);
                }
                break;

            case 'update':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for update action']);
                }

                if (empty($data) && empty($searchReplace)) {
                    throw new ValidationException(['Data is required for update action']);
                }
                break;

            case 'delete':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for delete action']);
                }
                break;

            case 'translate':
                if ($uid === null) {
                    throw new ValidationException(['Record UID is required for translate action']);
                }

                if (empty($data)) {
                    throw new ValidationException(['Data is required for translate action']);
                }

                if (!isset($data['sys_language_uid'])) {
                    throw new ValidationException(['sys_language_uid is required in data for translate action']);
                }
                break;

            default:
                throw new ValidationException(['Invalid action: ' . $action . '. Valid actions are: create, update, translate, delete']);
        }

        // Execute the action
        switch ($action) {
            case 'create':
                if ($pid === null) {
                    throw new LogicException('PID must be validated before create');
                }
                return $this->createRecord($table, $pid, $data, $position);

            case 'update':
                if ($uid === null) {
                    throw new LogicException('UID must be validated before update');
                }
                // Resolve search_replace into concrete field values and merge into data
                if (!empty($searchReplace)) {
                    $resolvedFields = $this->resolveSearchReplace($table, $uid, $searchReplace);
                    $data = array_merge($data, $resolvedFields);
                }
                return $this->updateRecord($table, $uid, $data);

            case 'delete':
                if ($uid === null) {
                    throw new LogicException('UID must be validated before delete');
                }
                return $this->deleteRecord($table, $uid);

            case 'translate':
                if ($uid === null) {
                    throw new LogicException('UID must be validated before translate');
                }
                // The language UID has already been converted from ISO code if needed
                $targetLanguageUid = is_numeric($data['sys_language_uid'] ?? null) ? (int) $data['sys_language_uid'] : 0;
                return $this->translateRecord($table, $uid, $targetLanguageUid);

            default:
                // This should never happen due to earlier validation
                throw new LogicException('Invalid action: ' . $action);
        }
    }

    /**
     * Create a new record
     */
    /**
     * @param RecordData $data
     */
    protected function createRecord(string $table, int $pid, array $data, string $position): CallToolResult
    {
        // Pre-validate page access for non-admin users
        $pageAccessError = $this->validatePageAccess($pid);
        if ($pageAccessError !== null) {
            return $this->createErrorResult($pageAccessError);
        }

        if ($table === 'pages' && !isset($data['doktype'])) {
            $data['doktype'] = self::DEFAULT_PAGE_DOKTYPE;
        }

        // Ensure language field is set for language-aware tables (needed for non-admin permission checks)
        $data = $this->ensureLanguageField($table, $data);

        // Pre-validate authMode permissions (e.g., CType values) for non-admin users
        $authModeError = $this->validateAuthModePermissions($table, $data);
        if ($authModeError !== null) {
            return $this->createErrorResult($authModeError);
        }

        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'create');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }

        // Extract file relations before inline relations (both modify $data in place)
        $fileRelations = $this->extractFileRelations($table, $data);

        // Extract inline relations before converting data
        $inlineRelations = $this->extractInlineRelations($table, $data);

        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);

        // Prepare the data array
        $newRecordData = $data;
        $newRecordData['pid'] = $pid;

        // Handle sorting for bottom position
        // Only set sorting if the table has a sorting field configured and not explicitly provided
        $sortingField = $this->tableAccessService->getSortingFieldName($table);
        if ($position === 'bottom' && $sortingField !== null && !isset($data[$sortingField])) {
            // Get the maximum sorting value and add some space
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);

            $maxSorting = $queryBuilder
                ->select($sortingField)
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
                )
                ->orderBy($sortingField, 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($maxSorting !== false && is_numeric($maxSorting)) {
                $newRecordData[$sortingField] = (int) $maxSorting + 128; // Add some space for future insertions
            }
        }

        // Create a unique ID for this new record
        $newId = 'NEW' . uniqid();

        // Initialize DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($dataHandler);

        // First, create the parent record without file relations
        $dataMap = [];
        $dataMap[$table][$newId] = $newRecordData;

        // Process the parent record first
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        // Check for errors in parent creation
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating record: ' . $this->formatDataHandlerErrors($dataHandler->errorLog));
        }

        // Get the UID of the newly created parent record
        $parentUid = $dataHandler->substNEWwithIDs[$newId] ?? null;

        if (!$parentUid) {
            return $this->createErrorResult('Error creating record: No UID returned');
        }

        // Get the live UID for inline relations if we're in a workspace
        $liveParentUid = $this->getLiveUid($table, $parentUid);

        // Now process inline relations with the resolved parent UID
        if (!empty($inlineRelations)) {
            $childDataMap = [];
            $this->processInlineRelations($childDataMap, $table, $parentUid, $pid, $inlineRelations);


            if (!empty($childDataMap)) {
                // Create a new DataHandler instance for child records
                $childDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $this->assignBackendUser($childDataHandler);
                $childDataHandler->start($childDataMap, []);
                $childDataHandler->process_datamap();


                // Check for errors in child creation
                if (!empty($childDataHandler->errorLog)) {
                    // Parent was created but children failed
                    return $this->createErrorResult(
                        'Parent record created but error creating child records: '
                        . implode(', ', $childDataHandler->errorLog),
                    );
                }

                // Update foreign fields for embedded relations
                foreach ($inlineRelations as $fieldName => $relationData) {
                    $config = $relationData['config'];
                    $foreignTable = \is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';
                    $foreignField = \is_string($config['foreign_field'] ?? null) ? $config['foreign_field'] : '';

                    if (empty($foreignTable) || empty($foreignField)) {
                        continue;
                    }

                    // Check if this is an embedded table
                    $isHiddenTable = $this->isHiddenTcaTable($foreignTable);

                    if ($isHiddenTable) {
                        // Collect the UIDs of created child records
                        $childUids = [];
                        foreach ($childDataHandler->substNEWwithIDs as $newId => $realId) {
                            if (\is_string($newId) && str_starts_with($newId, 'NEW') && isset($childDataMap[$foreignTable][$newId]) && is_numeric($realId)) {
                                $childUids[] = (int) $realId;
                            }
                        }

                        if (!empty($childUids)) {
                            // Update foreign field directly in database
                            // RelationHandler's writeForeignField is for MM relations, not direct foreign fields
                            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                                ->getConnectionForTable($foreignTable);

                            foreach ($childUids as $childUid) {
                                $connection->update(
                                    $foreignTable,
                                    [$foreignField => $liveParentUid],
                                    ['uid' => $childUid],
                                );
                            }
                        }
                    }
                }
            }
        }


        // Process file relations with the resolved parent UID
        if (!empty($fileRelations)) {
            $fileDataMap = [];
            $this->processFileRelations($fileDataMap, $table, (string) $parentUid, $pid, $fileRelations);

            if (!empty($fileDataMap)) {
                $fileDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $this->assignBackendUser($fileDataHandler);
                $fileDataHandler->start($fileDataMap, []);
                $fileDataHandler->process_datamap();

                if (!empty($fileDataHandler->errorLog)) {
                    return $this->createErrorResult(
                        'Parent record created but error attaching files: '
                        . implode(', ', $fileDataHandler->errorLog),
                    );
                }
            }
        }

        // Handle after/before positioning if needed
        if (preg_match('/^(after|before):(\d+)$/', $position, $positionMatches) === 1) {
            $positionType = $positionMatches[1];
            $referenceUid = (int) $positionMatches[2];

            // Set up the command map for moving the record
            $cmdMap = [];
            $cmdMap[$table][$parentUid]['move'] = [
                'action' => $positionType,
                'target' => $referenceUid,
            ];

            // Initialize a new DataHandler for the move operation
            $moveDataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $this->assignBackendUser($moveDataHandler);
            $moveDataHandler->start([], $cmdMap);
            $moveDataHandler->process_cmdmap();

            // Check for errors in the move operation
            if (!empty($moveDataHandler->errorLog)) {
                // The record was created but positioning failed
                $liveUid = $this->getLiveUid($table, $parentUid);
                return $this->createJsonResult([
                    'action' => 'create',
                    'table' => $table,
                    'uid' => $liveUid,
                    'warning' => 'Record created but positioning failed: ' . implode(', ', $moveDataHandler->errorLog),
                ]);
            }
        }

        // Get the live UID for workspace transparency
        $liveUid = $this->getLiveUid($table, $parentUid);

        // Return the result with live UID
        return $this->createJsonResult([
            'action' => 'create',
            'table' => $table,
            'uid' => $liveUid,
        ]);
    }

    /**
     * Update an existing record
     */
    /**
     * @param RecordData $data
     */
    protected function updateRecord(string $table, int $uid, array $data): CallToolResult
    {
        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'update', $uid);
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }

        // Extract file relations before inline relations (both modify $data in place)
        $fileRelations = $this->extractFileRelations($table, $data);

        // Extract inline relations before converting data
        $inlineRelations = $this->extractInlineRelations($table, $data);

        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);

        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);

        // First, update the parent record without file relations
        $dataMap = [$table => [$workspaceUid => $data]];

        // Update the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($dataHandler);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        // Check for errors in parent update
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
        }

        // Process file relations with the resolved parent UID
        if (!empty($fileRelations)) {
            $record = BackendUtility::getRecord($table, $workspaceUid, 'pid');
            $filePid = is_numeric($record['pid'] ?? null) ? (int) $record['pid'] : 0;

            $fileDataMap = [];
            $this->processFileRelations($fileDataMap, $table, (string) $workspaceUid, $filePid, $fileRelations);

            if (!empty($fileDataMap)) {
                $fileDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $this->assignBackendUser($fileDataHandler);
                $fileDataHandler->start($fileDataMap, []);
                $fileDataHandler->process_datamap();

                if (!empty($fileDataHandler->errorLog)) {
                    return $this->createErrorResult('Error attaching files: ' . implode(', ', $fileDataHandler->errorLog));
                }
            }
        }

        // Now process inline relations with the resolved parent UID
        if (!empty($inlineRelations)) {
            // Get record's pid for creating new inline records
            $record = BackendUtility::getRecord($table, $workspaceUid, 'pid');
            $pid = $record['pid'] ?? 0;

            $childDataMap = [];
            $this->processInlineRelations($childDataMap, $table, $workspaceUid, $pid, $inlineRelations, $uid);

            if (!empty($childDataMap)) {
                // Create a new DataHandler instance for child records
                $childDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $this->assignBackendUser($childDataHandler);
                $childDataHandler->start($childDataMap, []);
                $childDataHandler->process_datamap();

                // Check for errors in child processing
                if (!empty($childDataHandler->errorLog)) {
                    return $this->createErrorResult('Error processing inline relations: ' . implode(', ', $childDataHandler->errorLog));
                }

                // Update foreign fields for embedded relations
                foreach ($inlineRelations as $fieldName => $relationData) {
                    $config = $relationData['config'];
                    $foreignTable = \is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';
                    $foreignField = \is_string($config['foreign_field'] ?? null) ? $config['foreign_field'] : '';

                    if (empty($foreignTable) || empty($foreignField)) {
                        continue;
                    }

                    // Check if this is an embedded table
                    $isHiddenTable = $this->isHiddenTcaTable($foreignTable);

                    if ($isHiddenTable) {
                        // Collect the UIDs of created child records
                        $childUids = [];
                        foreach ($childDataHandler->substNEWwithIDs as $newId => $realId) {
                            if (\is_string($newId) && str_starts_with($newId, 'NEW') && isset($childDataMap[$foreignTable][$newId]) && is_numeric($realId)) {
                                $childUids[] = (int) $realId;
                            }
                        }

                        if (!empty($childUids)) {
                            // Update foreign field directly in database
                            // RelationHandler's writeForeignField is for MM relations, not direct foreign fields
                            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                                ->getConnectionForTable($foreignTable);

                            // In update context, $uid is already the live UID
                            foreach ($childUids as $childUid) {
                                $connection->update(
                                    $foreignTable,
                                    [$foreignField => $uid],
                                    ['uid' => $childUid],
                                );
                            }
                        }
                    }
                }
            }
        }

        // Return the result with the original live UID
        return $this->createJsonResult([
            'action' => 'update',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
        ]);
    }

    /**
     * Delete a record
     */
    protected function deleteRecord(string $table, int $uid): CallToolResult
    {
        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);

        // Delete the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($dataHandler);
        $dataHandler->start([], [$table => [$workspaceUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error deleting record: ' . implode(', ', $dataHandler->errorLog));
        }

        return $this->createJsonResult([
            'action' => 'delete',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
        ]);
    }

    /**
     * Translate a record to another language
     */
    protected function translateRecord(string $table, int $uid, int $targetLanguageUid): CallToolResult
    {
        if ($targetLanguageUid <= 0) {
            return $this->createErrorResult('Cannot translate into the default language (id=0). Provide a target language other than the default.');
        }

        $languageField = $this->tableAccessService->getLanguageFieldName($table);
        if (!$languageField) {
            return $this->createErrorResult('Table ' . $table . ' does not support translations');
        }

        $translationParentField = $this->tableAccessService->getTranslationParentFieldName($table);
        if (!$translationParentField) {
            return $this->createErrorResult('Table ' . $table . ' does not have a translation parent field configured');
        }

        $liveUid = $this->getLiveUid($table, $uid);

        $record = BackendUtility::getRecordWSOL($table, $liveUid);
        if (!$record) {
            return $this->createErrorResult('Record not found (uid=' . $uid . ')');
        }

        if (!empty($record[$translationParentField]) && (int) $record[$translationParentField] > 0) {
            return $this->createErrorResult('Cannot translate a record that is already a translation. Translate the original record (uid=' . $record[$translationParentField] . ') instead.');
        }

        if (!empty($record[$languageField]) && (int) $record[$languageField] > 0) {
            return $this->createErrorResult('Record uid=' . $uid . ' is already in language ' . $record[$languageField] . '. Only default-language records can be translated.');
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $existingTranslation = $queryBuilder
            ->select('uid', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($targetLanguageUid, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($existingTranslation) {
            $targetIsoCode = $this->languageService->getIsoCodeFromUid($targetLanguageUid) ?? (string) $targetLanguageUid;
            $existingTranslationUid = is_numeric($existingTranslation['uid'] ?? null) ? (int) $existingTranslation['uid'] : 0;
            return $this->createErrorResult(
                'Translation already exists for language "' . $targetIsoCode . '" (uid=' . $existingTranslationUid . ')',
            );
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $this->assignBackendUser($dataHandler);

        $cmdMap = [
            $table => [
                $liveUid => [
                    'localize' => $targetLanguageUid,
                ],
            ],
        ];

        try {
            $dataHandler->start([], $cmdMap);
            $dataHandler->process_cmdmap();
        } catch (UniqueConstraintViolationException) {
            $targetIsoCode = $this->languageService->getIsoCodeFromUid($targetLanguageUid) ?? (string) $targetLanguageUid;
            return $this->createErrorResult(
                'Translation already exists for language "' . $targetIsoCode . '"',
            );
        }

        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating translation: ' . implode(', ', $dataHandler->errorLog));
        }

        $newTranslationUid = $dataHandler->copyMappingArray[$table][$liveUid] ?? null;

        if (!$newTranslationUid) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $newTranslationUid = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq($translationParentField, $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($targetLanguageUid, ParameterType::INTEGER)),
                )
                ->orderBy('uid', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        }

        $targetIsoCode = $this->languageService->getIsoCodeFromUid($targetLanguageUid) ?? (string) $targetLanguageUid;

        return $this->createJsonResult([
            'action' => 'translate',
            'table' => $table,
            'sourceUid' => $uid,
            'translationUid' => $newTranslationUid ?: 'Translation created but UID could not be determined',
            'targetLanguage' => $targetIsoCode,
        ]);
    }

    /**
     * Validate record data against TCA
     *
     * @param RecordData $data
     * @param int|null $uid Record UID (required for update actions)
     * @return true|string True if valid, error message if invalid
     */
    protected function validateRecordData(string $table, array &$data, string $action, ?int $uid = null)
    {
        // Table access has already been validated by ensureTableAccess() before this method is called
        // No need to re-check table existence here

        // Special handling for uid and pid
        if (isset($data['uid'])) {
            return "Field 'uid' cannot be modified directly";
        }
        if (isset($data['pid']) && $action !== 'create') {
            return "Field 'pid' can only be set during record creation";
        }

        // Validate and convert field values
        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                continue;
            }

            // Check if field is accessible (filters out inaccessible inline relations)
            if (!$this->tableAccessService->canAccessField($table, $fieldName)) {
                return "Field '{$fieldName}' is not accessible";
            }

            // Validate field value
            $validationError = $this->tableAccessService->validateFieldValue($table, $fieldName, $value);
            if ($validationError !== null) {
                return $validationError;
            }

            // Handle date/time fields - convert ISO 8601 to timestamp for TYPO3
            $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $eval = \is_string($fieldOptions['eval'] ?? null) ? $fieldOptions['eval'] : '';
            if ($eval !== '') {
                $evalRules = GeneralUtility::trimExplode(',', $eval, true);
                if (array_intersect(['date', 'datetime', 'time'], $evalRules)) {
                    if (\is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                        try {
                            $dateTime = new DateTime($value);
                            $data[$fieldName] = $dateTime->getTimestamp();
                        } catch (Exception $e) {
                            // Log the error but let DataHandler handle the invalid date
                            $this->logException($e, 'parsing date value');
                        }
                    }
                }
            }

            // Validate file field type (accepts array of sys_file UIDs)
            if (($fieldOptions['type'] ?? null) === 'file') {
                $validationError = $this->validateFileFieldData($value);
                if ($validationError !== null) {
                    return "Field '{$fieldName}': " . $validationError;
                }
                continue;
            }

            // Validate inline field type
            if (($fieldOptions['type'] ?? null) === 'inline') {
                // Validate inline relation data
                $validationError = $this->validateInlineRelationData($fieldConfig, $value);
                if ($validationError !== null) {
                    return "Field '{$fieldName}': " . $validationError;
                }
                continue;
            }
            // Convert arrays to comma-separated strings for multi-value fields
            elseif (\is_array($value)) {
                $fieldType = \is_string($fieldOptions['type'] ?? null) ? $fieldOptions['type'] : '';
                if (\in_array($fieldType, ['select', 'category'])
                    || ($fieldType === 'group' && !empty($fieldOptions['multiple']))) {
                    $data[$fieldName] = implode(',', array_map(static fn(mixed $item): string => \is_scalar($item) ? (string) $item : '', $value));
                    continue;
                }

                if ($fieldType === 'flex') {
                    continue;
                }

                return "Field '{$fieldName}' does not accept array values";
            }
        }

        // After validating all field values, check field availability based on record type
        // This ensures type field validation happens first
        $recordType = '';
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        if ($typeField) {
            if ($action === 'update' && $uid !== null) {
                // For updates, fetch the current record type
                $currentRecord = BackendUtility::getRecord($table, $uid, $typeField);
                if ($currentRecord && isset($currentRecord[$typeField])) {
                    $recordType = \is_scalar($currentRecord[$typeField]) ? (string) $currentRecord[$typeField] : '';
                }
                // If type is being changed in the update, use the new type
                if (isset($data[$typeField])) {
                    $recordType = \is_scalar($data[$typeField]) ? (string) $data[$typeField] : '';
                }
            } else {
                // For creates, get type from data
                $recordType = isset($data[$typeField]) && \is_scalar($data[$typeField]) ? (string) $data[$typeField] : '';
            }
        }

        // Get available fields for this record type
        $availableFields = $this->tableAccessService->getAvailableFields($table, $recordType);

        // The type field itself should always be available if it exists
        if ($typeField) {
            $typeFieldConfig = $this->tableAccessService->getFieldConfig($table, $typeField);
            if ($typeFieldConfig) {
                $availableFields[$typeField] = $typeFieldConfig;
            }
        }

        // If we have type-specific configuration, validate field availability
        if (!empty($availableFields) || !empty($typeField)) {
            // Check each field in data is available
            foreach ($data as $fieldName => $value) {
                // Skip fields that don't exist in TCA (already validated above)
                if (!$this->tableAccessService->getFieldConfig($table, $fieldName)) {
                    continue;
                }

                // Special handling for FlexForm fields which are dynamically added
                if ($this->isFlexFormField($table, $fieldName)) {
                    // FlexForm fields are valid if they exist in TCA, even if not in showitem
                    continue;
                }

                // Special handling for passthrough fields (often used for inline relations)
                $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
                $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
                if (($fieldOptions['type'] ?? null) === 'passthrough') {
                    // Passthrough fields are valid if they exist in TCA, even if not in showitem
                    // Example: tx_news_related_news stores the foreign key for inline relations
                    continue;
                }


                // If we have available fields configured and this field is not in the list
                if (!empty($availableFields) && !isset($availableFields[$fieldName])) {
                    return "Field '{$fieldName}' is not available for this record type";
                }
            }
        }

        return true;
    }


    /**
     * Extract inline relations from data array
     */
    /**
     * @param RecordData $data
     * @return InlineRelations
     */
    protected function extractInlineRelations(string $table, array &$data): array
    {
        $inlineRelations = [];

        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldOptions = $fieldConfig !== null && isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            if (($fieldOptions['type'] ?? null) === 'inline') {
                $inlineRelations[$fieldName] = [
                    'config' => $fieldOptions,
                    'value' => $value,
                ];
                // Remove from data array as we'll process it separately
                unset($data[$fieldName]);
            }
        }

        return $inlineRelations;
    }

    /**
     * Process inline relations for DataHandler
     *
     * @param DataMap $dataMap
     * @param InlineRelations $inlineRelations
     */
    protected function processInlineRelations(
        array &$dataMap,
        string $parentTable,
        int $parentUid,
        int $pid,
        array $inlineRelations,
        ?int $liveUid = null,
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $value = $relationData['value'];
            $foreignTable = \is_string($config['foreign_table'] ?? null) ? $config['foreign_table'] : '';
            $foreignField = \is_string($config['foreign_field'] ?? null) ? $config['foreign_field'] : '';

            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }

            // Check if foreign table is hidden (embedded records)
            $isHiddenTable = $this->isHiddenTcaTable($foreignTable);

            if ($isHiddenTable) {
                if (!\is_array($value)) {
                    continue;
                }
                // Process embedded inline relations (e.g., tx_news_domain_model_link)
                $this->processEmbeddedInlineRelations($dataMap, $foreignTable, $foreignField, $parentUid, $pid, $value, $config, $liveUid);
            } else {
                if (!\is_array($value)) {
                    continue;
                }
                // Process independent inline relations (e.g., tt_content)
                $this->processIndependentInlineRelations($foreignTable, $foreignField, $parentUid, $value, $liveUid);
            }
        }
    }

    /**
     * Process embedded inline relations (hideTable=true)
     *
     * @param DataMap $dataMap
     * @param array<mixed, mixed> $records
     * @param array<string, mixed> $config
     */
    protected function processEmbeddedInlineRelations(
        array &$dataMap,
        string $foreignTable,
        string $foreignField,
        int $parentUid,
        int $pid,
        array $records,
        array $config,
        ?int $liveUid = null,
    ): void {
        // If we're updating, handle existing relations
        if ($liveUid !== null) {
            $this->handleExistingEmbeddedRelations($foreignTable, $foreignField, $liveUid, $records);
        }

        foreach ($records as $index => $recordData) {
            if (!\is_array($recordData)) {
                continue;
            }
            $recordData = $this->normalizeRecordData($recordData);

            // Create new ID for the inline record
            $newId = 'NEW' . uniqid() . '_' . $index;

            // Don't set the foreign field here - it will be handled by RelationHandler
            // Remove it if it was accidentally included
            unset($recordData[$foreignField]);
            $recordData['pid'] = $pid;

            // If we have a sorting field, set it
            if (\is_string($config['foreign_sortby'] ?? null) && $config['foreign_sortby'] !== '') {
                $recordData[$config['foreign_sortby']] = ((int) $index + 1) * 256;
            }

            // Add to data map
            if (!isset($dataMap[$foreignTable])) {
                $dataMap[$foreignTable] = [];
            }
            $dataMap[$foreignTable][$newId] = $recordData;
        }
    }

    /**
     * Process independent inline relations (UIDs only)
     *
     * @param array<mixed, mixed> $uids
     */
    protected function processIndependentInlineRelations(
        string $foreignTable,
        string $foreignField,
        int $parentUid,
        array $uids,
        ?int $liveUid = null,
    ): void {
        // For updates, we need to handle existing relations
        if ($liveUid !== null) {
            // First, clear existing relations
            $this->clearExistingInlineRelations($foreignTable, $foreignField, $liveUid);
        }

        // Update foreign field on specified records
        if (!empty($uids)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $this->assignBackendUser($dataHandler);

            $updateMap = [];
            foreach ($uids as $uid) {
                if (is_numeric($uid) && $uid > 0) {
                    $normalizedUid = (int) $uid;
                    $updateMap[$foreignTable][$normalizedUid] = [
                        $foreignField => $liveUid ?? $parentUid,
                    ];
                }
            }

            if (!empty($updateMap)) {
                $dataHandler->start($updateMap, []);
                $dataHandler->process_datamap();
            }
        }
    }

    /**
     * Clear existing inline relations
     */
    protected function clearExistingInlineRelations(string $foreignTable, string $foreignField, int $parentUid): void
    {
        // Get all records that currently have this parent
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($foreignTable);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()));

        $existingRecords = $queryBuilder
            ->select('uid')
            ->from($foreignTable)
            ->where(
                $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if (!empty($existingRecords)) {
            // Use DataHandler to clear relations to respect workspaces
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $this->assignBackendUser($dataHandler);

            $updateMap = [];
            foreach ($existingRecords as $record) {
                $recordUid = is_numeric($record['uid'] ?? null) ? (int) $record['uid'] : 0;
                if ($recordUid <= 0) {
                    continue;
                }
                $updateMap[$foreignTable][$recordUid] = [
                    $foreignField => 0,
                ];
            }

            if ($updateMap !== []) {
                $dataHandler->start($updateMap, []);
                $dataHandler->process_datamap();
            }
        }
    }

    /**
     * Handle existing embedded relations during updates
     *
     * @param array<mixed> $newRecords
     */
    protected function handleExistingEmbeddedRelations(
        string $foreignTable,
        string $foreignField,
        int $parentUid,
        array $newRecords,
    ): void {
        // Get all existing child records for this parent
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($foreignTable);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()));

        $existingRecords = $queryBuilder
            ->select('uid')
            ->from($foreignTable)
            ->where(
                $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if (!empty($existingRecords)) {
            // Extract UIDs of new records that have UIDs (updates)
            $keepUids = [];
            foreach ($newRecords as $record) {
                if (\is_array($record) && isset($record['uid']) && is_numeric($record['uid'])) {
                    $keepUids[] = (int) $record['uid'];
                }
            }

            // Delete records that are not in the new set
            $deleteUids = [];
            foreach ($existingRecords as $existingRecord) {
                $existingUid = is_numeric($existingRecord['uid'] ?? null) ? (int) $existingRecord['uid'] : 0;
                if ($existingUid > 0 && !\in_array($existingUid, $keepUids, true)) {
                    $deleteUids[] = $existingUid;
                }
            }

            if (!empty($deleteUids)) {
                // Use DataHandler to delete records (respects workspaces)
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $this->assignBackendUser($dataHandler);

                $cmdMap = [];
                foreach ($deleteUids as $deleteUid) {
                    $cmdMap[$foreignTable][$deleteUid]['delete'] = 1;
                }

                $dataHandler->start([], $cmdMap);
                $dataHandler->process_cmdmap();
            }
        }
    }

    /**
     * Validate file field data.
     *
     * File fields accept:
     * - An array of sys_file UIDs (integers)
     * - An array of objects with at least 'uid' (sys_file UID) and optional metadata
     *   like 'title', 'description', 'alternative', 'link', 'crop'
     */
    protected function validateFileFieldData(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return 'File field must be an array of sys_file UIDs or file reference objects';
        }

        foreach ($value as $index => $item) {
            if (is_numeric($item) && (int) $item > 0) {
                continue;
            }
            if (\is_array($item)) {
                $uid = $item['uid'] ?? null;
                if (!is_numeric($uid) || (int) $uid <= 0) {
                    return "File reference at index {$index} must have a positive integer 'uid' (sys_file UID)";
                }
                continue;
            }
            return "File field items must be positive integer UIDs or objects with 'uid' key (sys_file UID)";
        }

        return null;
    }

    /**
     * Extract file relations from data array.
     *
     * Pulls out fields with TCA type=file and returns them separately
     * so they can be processed as sys_file_reference records.
     *
     * @param RecordData $data
     * @return array<string, array{config: array<string, mixed>, value: mixed}>
     */
    protected function extractFileRelations(string $table, array &$data): array
    {
        $fileRelations = [];

        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldOptions = $fieldConfig !== null && isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            if (($fieldOptions['type'] ?? null) === 'file') {
                $fileRelations[$fieldName] = [
                    'config' => $fieldOptions,
                    'value' => $value,
                ];
                unset($data[$fieldName]);
            }
        }

        return $fileRelations;
    }

    /**
     * Process file relations by creating sys_file_reference records in the dataMap.
     *
     * Accepts an array of sys_file UIDs (or objects with uid + metadata) and creates
     * NEW* sys_file_reference entries. Sets the file field value on the parent record
     * to a comma-separated list of NEW* IDs so DataHandler wires everything correctly.
     *
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap DataMap to add sys_file_reference entries to
     * @param string $parentTable Parent table name (e.g. 'tt_content')
     * @param string $parentId Parent record ID (NEW* for creates, numeric for updates)
     * @param int $pid Page ID for the new sys_file_reference records
     * @param array<string, array{config: array<string, mixed>, value: mixed}> $fileRelations Extracted file relations
     */
    protected function processFileRelations(
        array &$dataMap,
        string $parentTable,
        string $parentId,
        int $pid,
        array $fileRelations,
    ): void {
        foreach ($fileRelations as $fieldName => $relationData) {
            $value = $relationData['value'];
            if (!\is_array($value) || empty($value)) {
                continue;
            }

            $newIds = [];
            foreach ($value as $index => $item) {
                $fileUid = null;
                $metadata = [];

                if (is_numeric($item)) {
                    $fileUid = (int) $item;
                } elseif (\is_array($item)) {
                    $fileUid = isset($item['uid']) && is_numeric($item['uid']) ? (int) $item['uid'] : null;
                    unset($item['uid']);
                    $metadata = $item;
                }

                if ($fileUid === null || $fileUid <= 0) {
                    continue;
                }

                $newRefId = 'NEW_file_' . uniqid() . '_' . $index;

                $refData = [
                    'uid_local' => $fileUid,
                    'uid_foreign' => $parentId,
                    'tablenames' => $parentTable,
                    'fieldname' => $fieldName,
                    'pid' => $pid,
                    'sorting_foreign' => ((int) $index + 1) * 256,
                ];

                $allowedMetadataFields = ['title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'showinpreview'];
                foreach ($allowedMetadataFields as $metaField) {
                    if (isset($metadata[$metaField])) {
                        $refData[$metaField] = $metadata[$metaField];
                    }
                }

                if (!isset($dataMap['sys_file_reference'])) {
                    $dataMap['sys_file_reference'] = [];
                }
                $dataMap['sys_file_reference'][$newRefId] = $refData;
                $newIds[] = $newRefId;
            }

            if (!empty($newIds)) {
                if (!isset($dataMap[$parentTable])) {
                    $dataMap[$parentTable] = [];
                }
                $dataMap[$parentTable][$parentId][$fieldName] = implode(',', $newIds);
            }
        }
    }

    /**
     * Validate inline relation data
     */
    /**
     * @param array<string, mixed> $fieldConfig
     */
    protected function validateInlineRelationData(array $fieldConfig, mixed $value): ?string
    {
        // Check if value is an array
        if (!\is_array($value)) {
            return 'Inline relation field must be an array of UIDs or record data';
        }

        // Get foreign table
        $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $foreignTable = \is_string($fieldOptions['foreign_table'] ?? null) ? $fieldOptions['foreign_table'] : '';
        if (empty($foreignTable)) {
            return 'Invalid inline relation configuration: missing foreign_table';
        }

        // Check if foreign table is hidden (embedded records)
        $isHiddenTable = $this->isHiddenTcaTable($foreignTable);

        // Validate each item
        foreach ($value as $index => $item) {
            if ($isHiddenTable) {
                // For hidden tables, expect record data arrays
                if (!\is_array($item)) {
                    return 'Embedded inline relations must contain record data arrays';
                }
                // Basic validation - must have at least one field
                if (empty($item)) {
                    return 'Embedded inline relation record at index ' . $index . ' is empty';
                }
            } else {
                // For independent tables, expect UIDs
                if (!is_numeric($item) || $item <= 0) {
                    return 'Independent inline relations must contain only positive integer UIDs';
                }
            }
        }

        return null;
    }

    /**
     * Check if a field is a FlexForm field
     */
    protected function isFlexFormField(string $table, string $fieldName): bool
    {
        return $this->tableAccessService->isFlexFormField($table, $fieldName);
    }

    /**
     * Extract search-and-replace operations from the data array.
     *
     * When a non-inline field value is an array of objects with 'search' and 'replace' keys,
     * it's treated as search-and-replace operations instead of a direct value assignment.
     * These are extracted from the data array and returned separately.
     *
     * @param string $table Table name
     * @param RecordData $data Data array (modified in place to remove search/replace entries)
     * @param string $action Current action (search/replace only valid for 'update')
     * @return SearchReplaceMap Map of field name => array of search/replace operations
     * @throws ValidationException If search/replace used in non-update action or operations are invalid
     */
    protected function extractSearchReplaceFromData(string $table, array &$data, string $action): array
    {
        $searchReplace = [];

        foreach ($data as $fieldName => $value) {
            if (!\is_array($value)) {
                continue;
            }

            // Check if this is an inline relation field — those genuinely use arrays
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldOptions = $fieldConfig !== null && isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            if (($fieldOptions['type'] ?? null) === 'inline') {
                continue;
            }

            // Check if this looks like search/replace operations:
            // sequential array of objects with 'search' and 'replace' keys
            if (!$this->isSearchReplaceArray($value)) {
                continue;
            }

            // Validate action — search/replace only works for update
            if ($action !== 'update') {
                throw new ValidationException(["Search-and-replace operations in data are only supported for the \"update\" action (field '{$fieldName}')"]);
            }

            // Validate each operation
            foreach ($value as $index => $operation) {
                if ($operation['search'] === '') {
                    throw new ValidationException(["Field '{$fieldName}' search-and-replace operation at index {$index} has an empty search string"]);
                }
            }

            $searchReplace[$fieldName] = $value;
            unset($data[$fieldName]);
        }

        return $searchReplace;
    }

    /**
     * Check if a value looks like an array of search/replace operations.
     *
     * Returns true if the value is a non-empty sequential array where every item
     * is an associative array with at least 'search' (string) and 'replace' (string) keys.
     */
    /**
     * @param array<mixed, mixed> $value
     * @phpstan-assert-if-true list<SearchReplaceOperation> $value
     */
    protected function isSearchReplaceArray(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Must be a sequential (non-associative) array
        if (array_keys($value) !== range(0, \count($value) - 1)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_array($item)) {
                return false;
            }
            if (!isset($item['search']) || !\is_string($item['search'])) {
                return false;
            }
            if (!\array_key_exists('replace', $item) || !\is_string($item['replace'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve search_replace operations into concrete field values.
     *
     * Fetches the current record (workspace-aware), validates field types,
     * applies search-and-replace operations sequentially, and returns
     * the resolved field values ready to merge into the data array.
     *
     * @param string $table Table name
     * @param int $uid Live record UID
     * @param SearchReplaceMap $searchReplace Map of field name => array of operations
     * @return RecordData Resolved field values (field name => new value)
     * @throws ValidationException If a field is not a string type or search string is not found/ambiguous
     */
    protected function resolveSearchReplace(string $table, int $uid, array $searchReplace): array
    {
        // String-storable TCA field types that support search_replace
        $stringFieldTypes = ['input', 'text', 'email', 'link', 'slug', 'color'];

        // Collect all field names we need to fetch
        $fieldNames = array_keys($searchReplace);

        // Validate all fields exist, are accessible, and are string-type before fetching the record.
        // Field access MUST be checked before any DB read to prevent information disclosure
        // via search/replace error messages ("not found" / "found N times").
        foreach ($fieldNames as $fieldName) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                throw new ValidationException(["search_replace field '{$fieldName}' does not exist in table '{$table}'"]);
            }
            if (!$this->tableAccessService->canAccessField($table, $fieldName)) {
                throw new ValidationException(["Field '{$fieldName}' is not accessible"]);
            }
            $fieldOptions = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $fieldType = \is_string($fieldOptions['type'] ?? null) ? $fieldOptions['type'] : '';
            if (!\in_array($fieldType, $stringFieldTypes, true)) {
                throw new ValidationException(["search_replace is not supported for field '{$fieldName}' (type: {$fieldType}). Only string fields (text, input, etc.) are supported."]);
            }
        }

        // Fetch full record with workspace overlay to get the current workspace version data,
        // which is what the LLM sees from ReadTable output.
        // We fetch all fields because workspaceOL needs uid and workspace metadata fields.
        $record = BackendUtility::getRecord($table, $uid);
        if (!$record) {
            throw new ValidationException(["Record {$uid} not found in table '{$table}'"]);
        }
        BackendUtility::workspaceOL($table, $record);

        $resolved = [];
        foreach ($searchReplace as $fieldName => $operations) {
            $currentValue = (string) ($record[$fieldName] ?? '');

            foreach ($operations as $index => $operation) {
                $search = $operation['search'];
                $replaceAll = !empty($operation['replaceAll']);
                $replace = $operation['replace'];

                $count = substr_count($currentValue, $search);

                if ($count === 0) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string not found in current field value"]);
                }

                if ($count > 1 && !$replaceAll) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string found {$count} times, must be unique. Set replaceAll to true to replace all occurrences."]);
                }

                if ($replaceAll) {
                    $currentValue = str_replace($search, $replace, $currentValue);
                } else {
                    // Replace only the first (and only) occurrence
                    $pos = strpos($currentValue, $search);
                    if ($pos === false) {
                        throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string not found in current field value"]);
                    }
                    $currentValue = substr_replace($currentValue, $replace, $pos, \strlen($search));
                }
            }

            $resolved[$fieldName] = $currentValue;
        }

        return $resolved;
    }

    /**
     * Convert data for storage
     */
    /**
     * @param RecordData $data
     * @return RecordData
     */
    protected function convertDataForStorage(string $table, array $data): array
    {
        // Process each field
        foreach ($data as $fieldName => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }

            // Normalize slug fields: trim all slashes, then prepend exactly one.
            // TYPO3's SlugNormalizer preserves trailing slashes if present in the input,
            // but the frontend routing always strips them. LLMs commonly produce slugs
            // with trailing slashes or missing leading slashes, so we normalize here.
            // The root page slug "/" is handled correctly: trim('/', '/') = '' → '/' + '' = '/'.
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldOptions = $fieldConfig !== null && isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            if (($fieldOptions['type'] ?? null) === 'slug' && \is_string($value)) {
                $data[$fieldName] = '/' . trim($value, '/');
            }

            // Handle FlexForm fields
            if ($this->isFlexFormField($table, $fieldName)) {
                // If the value is already a string (XML), keep it as is
                if (\is_string($value) && str_starts_with($value, '<?xml')) {
                    continue;
                }

                // If the value is an array or JSON string, convert it to XML
                $decodedFlexForm = \is_string($value) && str_starts_with($value, '{') ? json_decode($value, true) : null;
                $flexFormArray = \is_array($value) ? $value : (\is_array($decodedFlexForm) ? $decodedFlexForm : null);

                if (\is_array($flexFormArray)) {
                    // Prepare the data structure for TYPO3's XML conversion
                    $flexFormData = [
                        'data' => [
                            'sDEF' => [
                                'lDEF' => [],
                            ],
                        ],
                    ];

                    // Process settings fields
                    if (isset($flexFormArray['settings']) && \is_array($flexFormArray['settings'])) {
                        foreach ($flexFormArray['settings'] as $settingKey => $settingValue) {
                            $flexFormData['data']['sDEF']['lDEF']['settings.' . $settingKey]['vDEF'] = $settingValue;
                        }
                    }

                    // Process other fields
                    foreach ($flexFormArray as $key => $val) {
                        if ($key !== 'settings' && !\is_array($val)) {
                            $flexFormData['data']['sDEF']['lDEF'][$key]['vDEF'] = $val;
                        }
                    }

                    // Use TYPO3's GeneralUtility::array2xml to convert the array to XML
                    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n";
                    $xml .= GeneralUtility::array2xml($flexFormData, '', 0, 'T3FlexForms');

                    $data[$fieldName] = $xml;
                }
            }
        }

        return $data;
    }

    /**
     * Get the live UID for a workspace record
     * For workspace records, this returns the t3ver_oid (original/live UID)
     * For new records (placeholders), this returns the placeholder UID
     */
    protected function getLiveUid(string $table, int $workspaceUid): int
    {
        // If we're in live workspace, the UID is already the live UID
        $currentWorkspace = $this->getCurrentWorkspaceId();
        if ($currentWorkspace === 0) {
            return $workspaceUid;
        }

        // Look up the record to get its t3ver_oid
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder
            ->select('t3ver_oid', 't3ver_state', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            // Record not found, return the original UID
            return $workspaceUid;
        }

        // If this is a workspace record with an original, return the original UID
        $originalUid = is_numeric($record['t3ver_oid'] ?? null) ? (int) $record['t3ver_oid'] : 0;
        if ($originalUid > 0) {
            return $originalUid;
        }

        // For new records (t3ver_state = 1), the workspace UID IS the UID we should use
        // New records don't have a live counterpart until published
        if (is_numeric($record['t3ver_state'] ?? null) && (int) $record['t3ver_state'] === 1) {
            return $workspaceUid;
        }

        // Default: return the workspace UID
        return $workspaceUid;
    }

    /**
     * Resolve a live UID to its workspace version
     * Used for update/delete operations where we receive a live UID but need the workspace version
     */
    protected function resolveToWorkspaceUid(string $table, int $liveUid): int
    {
        $currentWorkspace = $this->getCurrentWorkspaceId();

        // If we're in live workspace, no resolution needed
        if ($currentWorkspace === 0) {
            return $liveUid;
        }

        // Use BackendUtility to get the workspace version
        $record = BackendUtility::getRecord($table, $liveUid);
        if (!$record) {
            return $liveUid;
        }

        // Let BackendUtility handle the workspace overlay
        BackendUtility::workspaceOL($table, $record);

        // If we got a different UID, that's the workspace version
        if (isset($record['_ORIG_uid']) && $record['_ORIG_uid'] != $liveUid) {
            return (int) $record['uid'];
        }

        return $liveUid;
    }

    /**
     * Ensure sys_language_uid is set for language-aware tables.
     * Non-admin users require this field to be set for language permission checks.
     *
     * Note: This method only adds the language field if it's not already set and
     * the table supports it. The validation step will catch if the field is not
     * available for the specific record type.
     *
     * @param string $table Table name
     * @param RecordData $data Record data
     * @return RecordData Modified data with sys_language_uid if needed
     */
    protected function ensureLanguageField(string $table, array $data): array
    {
        // Only modify data for non-admin users who need this for permission checks
        $beUser = $this->getBackendUser();
        if ($beUser->isAdmin()) {
            return $data;
        }

        $languageField = $this->tableAccessService->getLanguageFieldName($table);

        // If table has no language field, nothing to do
        if ($languageField === null) {
            return $data;
        }

        // If language field is already set, keep it
        if (isset($data[$languageField])) {
            return $data;
        }

        // Get the type field to check if language field is available for this type
        $typeFieldName = $this->tableAccessService->getTypeFieldName($table);
        $type = '';
        if ($typeFieldName !== null && isset($data[$typeFieldName])) {
            $type = \is_scalar($data[$typeFieldName]) ? (string) $data[$typeFieldName] : '';
        }

        // Check if the language field is actually available for this record type
        if (!$this->tableAccessService->canAccessField($table, $languageField, $type)) {
            // Language field is not available for this type, don't add it
            return $data;
        }

        // Default to default language (0) for create operations
        $data[$languageField] = 0;

        return $data;
    }

    /**
     * Validate that the current user has access to the target page.
     * This checks webmounts for non-admin users.
     *
     * @param int $pid Target page ID
     * @return string|null Error message if access denied, null if access granted
     */
    protected function validatePageAccess(int $pid): ?string
    {
        $beUser = $this->getBackendUser();

        if ($pid > 0) {
            $pageRecord = BackendUtility::getRecord('pages', $pid, 'uid');
            if (!\is_array($pageRecord) || !isset($pageRecord['uid'])) {
                return \sprintf(
                    'Invalid parent page: Page %d does not exist or is not accessible.',
                    $pid,
                );
            }
        }

        // Admin users have access to all pages
        if ($beUser->isAdmin()) {
            return null;
        }

        // Check if user has access to this page through webmounts
        if (!$beUser->isInWebMount($pid)) {
            return \sprintf(
                'Permission denied: You do not have access to page %d. Your account needs database mount point (DB Mount) '
                . 'access to this page or its parent pages. Contact your administrator.',
                $pid,
            );
        }

        return null;
    }

    /**
     * Validate authMode permissions for fields like CType.
     * Non-admin users need explicit permissions for certain field values.
     *
     * @param string $table Table name
     * @param RecordData $data Record data
     * @return string|null Error message if permission denied, null if all permissions granted
     */
    protected function validateAuthModePermissions(string $table, array $data): ?string
    {
        $beUser = $this->getBackendUser();

        // Admin users bypass authMode checks
        if ($beUser->isAdmin()) {
            return null;
        }

        foreach ($data as $fieldName => $value) {
            $field = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if ($field === null) {
                continue;
            }

            $fieldConfig = isset($field['config']) && \is_array($field['config']) ? $field['config'] : [];
            $authMode = $fieldConfig['authMode'] ?? null;

            // Only check fields with authMode configured
            if ($authMode === null) {
                continue;
            }

            $authValue = \is_scalar($value) || $value === null ? (string) $value : '';

            // Check if user has permission for this value
            if (!$beUser->checkAuthMode($table, $fieldName, $authValue)) {
                $fieldLabel = $this->tableAccessService->translateLabel(
                    \is_string($field['label'] ?? null) ? $field['label'] : $fieldName,
                );

                // Collect allowed values for this field
                $allowedValues = $this->getAllowedAuthModeValues($table, $fieldName, $fieldConfig);

                $errorMsg = \sprintf(
                    'You do not have permission to use %s="%s" for field "%s".',
                    $fieldName,
                    $authValue,
                    $fieldLabel,
                );

                if (!empty($allowedValues)) {
                    $errorMsg .= ' Allowed values for your user: ' . implode(', ', $allowedValues) . '.';
                } else {
                    $errorMsg .= ' No values are allowed for your user group. Contact your administrator.';
                }

                return $errorMsg;
            }
        }

        return null;
    }

    /**
     * Get allowed authMode values for the current user.
     *
     * @param string $table Table name
     * @param string $fieldName Field name
     * @param array<string, mixed> $fieldConfig Field configuration
     * @return list<string> List of allowed values
     */
    protected function getAllowedAuthModeValues(string $table, string $fieldName, array $fieldConfig): array
    {
        $beUser = $this->getBackendUser();
        $allowedValues = [];

        // Get all possible values from the field config
        $items = isset($fieldConfig['items']) && \is_array($fieldConfig['items']) ? $fieldConfig['items'] : [];
        $parsed = $this->tableAccessService->parseSelectItems($items, true); // Skip dividers

        foreach ($parsed['values'] as $itemValue) {
            if ($beUser->checkAuthMode($table, $fieldName, $itemValue)) {
                $label = $parsed['labels'][$itemValue] ?? '';
                $translatedLabel = $this->tableAccessService->translateLabel($label);
                $allowedValues[] = $itemValue . ' (' . $translatedLabel . ')';
            }
        }

        return $allowedValues;
    }

    /**
     * Format DataHandler error messages into user-friendly messages.
     *
     * @param array<int, mixed> $errorLog DataHandler error log
     * @return string Formatted error message
     */
    protected function formatDataHandlerErrors(array $errorLog): string
    {
        $errors = [];

        foreach ($errorLog as $error) {
            if (!\is_string($error)) {
                continue;
            }
            // Parse common TYPO3 DataHandler error patterns
            if (str_contains($error, 'Attempt to insert record on pages:')) {
                if (str_contains($error, 'not allowed')) {
                    $errors[] = 'Cannot create record on this page. Check that you have database mount point access '
                        . 'and the necessary table permissions.';
                    continue;
                }
            }

            if (str_contains($error, 'recordEditAccessInternals()')) {
                if (str_contains($error, 'authMode')) {
                    // Already handled by validateAuthModePermissions, but show if it slipped through
                    preg_match('/field "([^"]+)" with value "([^"]+)"/', $error, $matches);
                    if (\count($matches) === 3) {
                        $errors[] = \sprintf(
                            'Permission denied for %s="%s". Your user group needs explicit permission for this value.',
                            $matches[1],
                            $matches[2],
                        );
                        continue;
                    }
                }

                if (str_contains($error, 'languageField')) {
                    $errors[] = 'Language permission check failed. Ensure sys_language_uid is set in your data.';
                    continue;
                }
            }

            // Default: include original error
            $errors[] = $error;
        }

        return implode(' | ', $errors);
    }
}
