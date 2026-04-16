<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
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
                . 'Operations are executed atomically — use WriteTable for complex single-record operations '
                . 'that need positioning, translation, or search-and-replace.',
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
                                'data' => [
                                    'type' => 'object',
                                    'description' => 'Field values for create or update',
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
                    $data = is_array($op['data'] ?? null) ? $op['data'] : [];
                    if ($data === []) {
                        $errors[] = 'Operation #' . $index . ': data is required for create';
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
