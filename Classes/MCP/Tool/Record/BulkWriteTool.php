<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\BatchedRecordPositioningService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Execute multiple write operations in a single DataHandler transaction.
 *
 * More efficient than calling WriteTable N times. Supports create, update,
 * and delete in a single call. Capped at 50 operations per call.
 */
final class BulkWriteTool extends AbstractRecordTool
{
    private const MAX_OPERATIONS = 50;

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly BatchedRecordPositioningService $batchedRecordPositioningService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Execute multiple write operations (create, update, delete) in a single transaction. '
                . 'More efficient than calling WriteTable multiple times. '
                . 'All operations run in workspace context. Maximum 50 operations per call. '
                . 'LIMITATION: BulkWrite does NOT support inline child records in "data" (no nested arrays of child records, no sys_file_reference objects). '
                . 'For any operation that needs inline children (image/assets on tt_content, nested container elements, etc.), use WriteTable. '
                . 'Other limitations: no positioning, no translate action, no search-and-replace — use WriteTable for those. ' .
                'BulkWrite is best for flat field updates and bulk create/delete. '
                . 'Multiple sortable create operations for the same pid are appended in operation order.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'operations' => [
                        'type' => 'array',
                        'description' => 'Array of write operations to execute',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'action' => [
                                    'type' => 'string',
                                    'description' => 'Operation type',
                                    'enum' => ['create', 'update', 'delete'],
                                ],
                                'table' => [
                                    'type' => 'string',
                                    'description' => 'Table name',
                                ],
                                'uid' => [
                                    'type' => 'integer',
                                    'description' => 'Record UID (required for update and delete)',
                                ],
                                'pid' => [
                                    'type' => 'integer',
                                    'description' => 'Page ID (required for create)',
                                ],
                                'allowRootLevelPageCreation' => [
                                    'type' => 'boolean',
                                    'description' => 'create action with table="pages" only. Defaults to false so accidental website/root-page creation at pid=0 is rejected. '
                                        . 'For new websites, use CreateSite with parentPageId. Set true only for intentional TYPO3 root-level pages.',
                                    'default' => false,
                                ],
                                'data' => [
                                    'type' => 'object',
                                    'description' => 'Flat field values for create or update. NOTE: Inline children (nested arrays of child records) are not supported here — use WriteTable instead.',
                                    'additionalProperties' => true,
                                ],
                            ],
                            'required' => ['action', 'table'],
                        ],
                        'minItems' => 1,
                        'maxItems' => self::MAX_OPERATIONS,
                    ],
                ],
                'required' => ['operations'],
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
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        $operations = is_array($params['operations'] ?? null) ? $params['operations'] : [];
        if ($operations === []) {
            throw new ValidationException(['operations array is required and must not be empty']);
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            throw new ValidationException([
                'Maximum ' . self::MAX_OPERATIONS . ' operations per call. '
                . 'Received ' . count($operations) . '. Split into multiple BulkWrite calls.',
            ]);
        }

        // Validate all operations upfront
        $validatedOps = $this->validateOperations(array_values($operations));

        // Build combined DataHandler maps
        /** @var array<string, array<int|string, array<string, mixed>>> $dataMap */
        $dataMap = [];
        /** @var array<string, array<int, array<string, mixed>>> $cmdMap */
        $cmdMap = [];
        $createCounters = [];

        foreach ($validatedOps as $index => $op) {
            $table = $op['table'];
            $action = $op['action'];

            switch ($action) {
                case 'create':
                    $pid = array_key_exists('pid', $op) ? $op['pid'] : 0;
                    $data = array_key_exists('data', $op) ? $op['data'] : [];
                    $data['pid'] = $pid;
                    $newId = 'NEW_bulk_' . $index;
                    $dataMap[$table][$newId] = $data;
                    break;

                case 'update':
                    $uid = array_key_exists('uid', $op) ? $op['uid'] : 0;
                    $data = array_key_exists('data', $op) ? $op['data'] : [];
                    $dataMap[$table][$uid] = $data;
                    break;

                case 'delete':
                    $uid = array_key_exists('uid', $op) ? $op['uid'] : 0;
                    $cmdMap[$table][$uid] = ['delete' => true];
                    break;
            }
        }

        $dataMap = $this->batchedRecordPositioningService->assignAppendPositions($dataMap);

        // Execute
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;

        if ($dataMap !== []) {
            $dataHandler->start($dataMap, $cmdMap);
            $dataHandler->process_datamap();
        }

        if ($cmdMap !== []) {
            if ($dataMap === []) {
                $dataHandler->start([], $cmdMap);
            }
            $dataHandler->process_cmdmap();
        }

        // Build per-operation results
        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($validatedOps as $index => $op) {
            $opResult = [
                'index' => $index,
                'action' => $op['action'],
                'table' => $op['table'],
            ];

            switch ($op['action']) {
                case 'create':
                    $newId = 'NEW_bulk_' . $index;
                    $substUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
                    if (is_numeric($substUid) && (int)$substUid > 0) {
                        $opResult['success'] = true;
                        $opResult['newUid'] = (int)$substUid;
                        $successCount++;
                    } else {
                        $opResult['success'] = false;
                        $opResult['error'] = 'Record creation failed';
                        $errorCount++;
                    }
                    break;

                case 'update':
                    $opResult['uid'] = array_key_exists('uid', $op) ? $op['uid'] : 0;
                    $opResult['success'] = true;
                    $successCount++;
                    break;

                case 'delete':
                    $opResult['uid'] = array_key_exists('uid', $op) ? $op['uid'] : 0;
                    $opResult['success'] = true;
                    $successCount++;
                    break;
            }

            $results[] = $opResult;
        }

        // Collect global errors
        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = is_scalar($error) ? (string)$error : 'Unknown error';
        }

        if ($errors !== []) {
            $errorCount = max($errorCount, 1);
        }

        $response = [
            'totalOperations' => count($validatedOps),
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'results' => $results,
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return $this->createJsonResult($response);
    }

    /**
     * Detect whether $data contains inline-child payloads on fields of type "inline" or "file".
     *
     * Nested objects/arrays of child records cannot be resolved through DataHandler's flat
     * dataMap and would either silently drop the children or cause an "unexpected error".
     * Return a structured message pointing the caller to WriteTable, or null if $data is safe.
     *
     * @param array<string, mixed> $data
     */
    /**
     * Mass-assignment defense: keep MCP clients from setting workspace plumbing,
     * audit columns, or core permission fields. DataHandler sanitizes most of
     * these too, but defense-in-depth: bail out at validation time so the
     * caller sees a structured error rather than a silently dropped value.
     *
     * Mirrors the rejection-list `WriteTableTool::validateRecordData` enforces
     * for `uid`/`pid` (extended here for the rest of the system fields).
     *
     * @param array<int|string, mixed> $data
     */
    private function rejectForbiddenSystemFields(int $opIndex, array $data): ?string
    {
        $forbidden = [
            'uid', 'pid',                                        // identity
            't3ver_oid', 't3ver_wsid', 't3ver_state',
            't3ver_stage', 't3ver_tstamp', 't3ver_count',         // workspace plumbing
            'deleted', 'tstamp', 'crdate', 'cruser_id',           // audit
            'perms_userid', 'perms_groupid',
            'perms_user', 'perms_group', 'perms_everybody',       // page perms
        ];
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $data)) {
                return sprintf(
                    'Operation #%d: field "%s" cannot be set directly via MCP — it is a system column.',
                    $opIndex,
                    $field,
                );
            }
        }
        return null;
    }

    private function detectInlineChildData(string $table, array $data): ?string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca)) {
            return null;
        }
        $tableConfig = $tca[$table] ?? null;
        if (!is_array($tableConfig)) {
            return null;
        }
        $columns = $tableConfig['columns'] ?? null;
        if (!is_array($columns)) {
            return null;
        }

        foreach ($data as $fieldName => $value) {
            if (!is_string($fieldName) || !isset($columns[$fieldName])) {
                continue;
            }
            $fieldConfig = $columns[$fieldName];
            if (!is_array($fieldConfig)) {
                continue;
            }
            $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
            $type = is_string($config['type'] ?? null) ? $config['type'] : '';
            if (!in_array($type, ['inline', 'file'], true)) {
                continue;
            }
            if (!is_array($value) || $value === []) {
                continue;
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    return 'Field "' . $fieldName . '" contains inline child records, which BulkWrite does not support. '
                        . 'Use the WriteTable tool for operations with inline children (image/assets on tt_content, container elements, nested records, etc.).';
                }
            }
        }

        return null;
    }

    /**
     * Validate all operations and return normalized operation arrays.
     *
     * @param list<mixed> $operations
     * @return list<array{action: string, table: string, uid?: int, pid?: int, data?: array<string, mixed>}>
     * @throws ValidationException
     */
    private function validateOperations(array $operations): array
    {
        $validated = [];
        $errors = [];

        foreach ($operations as $index => $op) {
            if (!is_array($op)) {
                $errors[] = 'Operation #' . $index . ': must be an object';
                continue;
            }

            $action = is_string($op['action'] ?? null) ? $op['action'] : '';
            $table = is_string($op['table'] ?? null) ? $op['table'] : '';

            if ($action === '' || !in_array($action, ['create', 'update', 'delete'], true)) {
                $errors[] = 'Operation #' . $index . ': action must be "create", "update", or "delete"';
                continue;
            }

            if ($table === '') {
                $errors[] = 'Operation #' . $index . ': table is required';
                continue;
            }

            // Validate table access
            try {
                $operationType = $action === 'delete' ? 'delete' : 'write';
                $this->ensureTableAccess($table, $operationType);
            } catch (\Throwable $e) {
                $errors[] = 'Operation #' . $index . ': ' . $e->getMessage();
                continue;
            }

            $entry = ['action' => $action, 'table' => $table];

            switch ($action) {
                case 'create':
                    $pid = is_numeric($op['pid'] ?? null) ? (int)$op['pid'] : -1;
                    if ($pid < 0) {
                        $errors[] = 'Operation #' . $index . ': pid is required for create';
                        continue 2;
                    }
                    if ($table === 'pages' && $pid === 0 && ($op['allowRootLevelPageCreation'] ?? false) !== true) {
                        $errors[] = 'Operation #' . $index . ': creating pages at pid=0 is reserved for intentional TYPO3 root-level pages. '
                            . 'For a new website, use CreateSite with parentPageId. '
                            . 'If you really need a root-level page, pass allowRootLevelPageCreation=true.';
                        continue 2;
                    }
                    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
                    if ($data === []) {
                        $errors[] = 'Operation #' . $index . ': data is required for create';
                        continue 2;
                    }
                    $inlineError = $this->detectInlineChildData($table, $data);
                    if ($inlineError !== null) {
                        $errors[] = 'Operation #' . $index . ': ' . $inlineError;
                        continue 2;
                    }
                    $forbiddenError = $this->rejectForbiddenSystemFields($index, $data);
                    if ($forbiddenError !== null) {
                        $errors[] = $forbiddenError;
                        continue 2;
                    }
                    $normalizedData = [];
                    foreach ($data as $key => $value) {
                        if (is_string($key)) {
                            $normalizedData[$key] = $value;
                        }
                    }
                    $entry['pid'] = $pid;
                    $entry['data'] = $normalizedData;
                    break;

                case 'update':
                    $uid = is_numeric($op['uid'] ?? null) ? (int)$op['uid'] : 0;
                    if ($uid < 1) {
                        $errors[] = 'Operation #' . $index . ': uid is required for update';
                        continue 2;
                    }
                    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
                    if ($data === []) {
                        $errors[] = 'Operation #' . $index . ': data is required for update';
                        continue 2;
                    }
                    $inlineError = $this->detectInlineChildData($table, $data);
                    if ($inlineError !== null) {
                        $errors[] = 'Operation #' . $index . ': ' . $inlineError;
                        continue 2;
                    }
                    $forbiddenError = $this->rejectForbiddenSystemFields($index, $data);
                    if ($forbiddenError !== null) {
                        $errors[] = $forbiddenError;
                        continue 2;
                    }
                    $normalizedData = [];
                    foreach ($data as $key => $value) {
                        if (is_string($key)) {
                            $normalizedData[$key] = $value;
                        }
                    }
                    $entry['uid'] = $uid;
                    $entry['data'] = $normalizedData;
                    break;

                case 'delete':
                    $uid = is_numeric($op['uid'] ?? null) ? (int)$op['uid'] : 0;
                    if ($uid < 1) {
                        $errors[] = 'Operation #' . $index . ': uid is required for delete';
                        continue 2;
                    }
                    $entry['uid'] = $uid;
                    break;
            }

            $validated[] = $entry;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }
}
