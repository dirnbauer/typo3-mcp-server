<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copy/duplicate records using DataHandler's native copy command.
 *
 * Preserves file references, relations, and workspace versioning automatically.
 */
final class CopyContentTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly LanguageService $languageService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        $writableTables = [];
        foreach ($accessibleTables as $table => $label) {
            $accessInfo = $this->tableAccessService->getTableAccessInfo($table);
            if (!$accessInfo['read_only']) {
                $writableTables[] = $table;
            }
        }
        sort($writableTables);

        return [
            'description' => 'Copy/duplicate a record to the same or different page. '
                . 'Preserves all field values, file references, and relations. '
                . 'The copy is created in the current workspace (workspace-safe). '
                . 'Optionally override specific field values in the copy. '
                . 'More efficient than reading a record and recreating it with WriteTable.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table name of the record to copy',
                        'enum' => $writableTables,
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'UID of the source record to copy',
                    ],
                    'targetPid' => [
                        'type' => 'integer',
                        'description' => 'Page ID where the copy should be placed. Use the same PID as the source to duplicate on the same page.',
                    ],
                    'overrides' => [
                        'type' => 'object',
                        'description' => 'Optional field values to override in the copy. '
                            . 'Example: {"header": "Copy of Original", "hidden": 1}',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['table', 'uid', 'targetPid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
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
        $table = is_string($params['table'] ?? null) ? $params['table'] : '';
        $uid = is_numeric($params['uid'] ?? null) ? (int)$params['uid'] : 0;
        $targetPid = is_numeric($params['targetPid'] ?? null) ? (int)$params['targetPid'] : 0;
        $overrides = is_array($params['overrides'] ?? null) ? $params['overrides'] : [];

        if ($table === '') {
            throw new ValidationException(['table parameter is required']);
        }
        if ($uid < 1) {
            throw new ValidationException(['uid must be a positive integer']);
        }

        // Validate table access
        $this->ensureTableAccess($table, 'write');

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->createErrorResult('No backend user session available.');
        }

        // Execute the copy via DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;

        $cmdMap = [
            $table => [
                $uid => [
                    'copy' => $targetPid,
                ],
            ],
        ];

        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();

        // Check for errors
        if ($dataHandler->errorLog !== []) {
            $errors = [];
            foreach ($dataHandler->errorLog as $error) {
                $errors[] = is_scalar($error) ? (string)$error : 'Unknown error';
            }
            throw new ValidationException($errors);
        }

        // Get the new record UID
        $newUid = 0;
        $copyMappingTable = $dataHandler->copyMappingArray[$table] ?? [];
        if (is_array($copyMappingTable)) {
            foreach ($copyMappingTable as $originalUid => $copiedUid) {
                if ((int)$originalUid === $uid && is_numeric($copiedUid)) {
                    $newUid = (int)$copiedUid;
                    break;
                }
            }
        }

        if ($newUid === 0) {
            return $this->createErrorResult('Copy command executed but could not determine the new record UID.');
        }

        // Apply overrides if provided
        $overridesApplied = [];
        if ($overrides !== []) {
            $normalizedOverrides = [];
            foreach ($overrides as $key => $value) {
                if (is_string($key)) {
                    // Handle language ISO code conversion
                    if ($key === 'sys_language_uid' && is_string($value)) {
                        $langUid = $this->languageService->getUidFromIsoCode($value);
                        if ($langUid !== null) {
                            $normalizedOverrides[$key] = $langUid;
                            $overridesApplied[$key] = $value . ' (uid: ' . $langUid . ')';
                            continue;
                        }
                    }
                    $normalizedOverrides[$key] = $value;
                    $overridesApplied[$key] = $value;
                }
            }

            if ($normalizedOverrides !== []) {
                $updateDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $updateDataHandler->BE_USER = $backendUser;
                $updateDataHandler->start(
                    [$table => [$newUid => $normalizedOverrides]],
                    [],
                );
                $updateDataHandler->process_datamap();

                if ($updateDataHandler->errorLog !== []) {
                    // Copy succeeded but overrides failed - report partial success
                    $overrideErrors = [];
                    foreach ($updateDataHandler->errorLog as $error) {
                        $overrideErrors[] = is_scalar($error) ? (string)$error : 'Unknown error';
                    }
                    $result = [
                        'table' => $table,
                        'sourceUid' => $uid,
                        'newUid' => $newUid,
                        'targetPid' => $targetPid,
                        'warning' => 'Record copied but some overrides failed: ' . implode('; ', $overrideErrors),
                    ];

                    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
                    return new CallToolResult([new TextContent($json)]);
                }
            }
        }

        $result = [
            'table' => $table,
            'sourceUid' => $uid,
            'newUid' => $newUid,
            'targetPid' => $targetPid,
        ];

        if ($overridesApplied !== []) {
            $result['overridesApplied'] = $overridesApplied;
        }

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)]);
    }
}
