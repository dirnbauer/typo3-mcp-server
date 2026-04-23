<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Publish pending workspace changes to live.
 *
 * Uses TYPO3's native WorkspaceService to build the publish command map
 * and DataHandler to execute it. Defaults to dry-run mode for safety.
 */
final class PublishWorkspaceTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly WorkspaceService $workspaceService,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Publish pending workspace changes to live. '
                . 'Defaults to dry-run mode (dryRun=true) which shows what would be published without executing. '
                . 'Set dryRun=false to actually publish. Use WorkspaceReview first to inspect changes. '
                . 'Publishing is irreversible — changes become live immediately.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: only publish changes for this specific table',
                    ],
                    'tables' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional: publish changes for multiple tables (more flexible than "table").',
                    ],
                    'onlyTranslations' => [
                        'type' => 'boolean',
                        'description' => 'Optional: when true, only publish translation records (records where sys_language_uid > 0). Useful to roll out translations separately from source-language edits.',
                        'default' => false,
                    ],
                    'dryRun' => [
                        'type' => 'boolean',
                        'description' => 'If true (default), preview what would be published without executing. '
                            . 'Set to false to actually publish changes to live.',
                        'default' => true,
                    ],
                ],
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

        $workspaceId = $backendUser->workspace;
        if ($workspaceId === 0) {
            return $this->createErrorResult(
                'Cannot publish from the live workspace. Switch to a draft workspace first or provide workspace_id.',
            );
        }

        // Validate publish access
        $workspaceRecord = $backendUser->checkWorkspace($workspaceId);
        if (!is_array($workspaceRecord) || !isset($workspaceRecord['_ACCESS'])) {
            return $this->createErrorResult('Cannot access workspace #' . $workspaceId . '.');
        }

        $accessLevel = is_string($workspaceRecord['_ACCESS']) ? $workspaceRecord['_ACCESS'] : '';
        if (!in_array($accessLevel, ['owner', 'admin'], true)) {
            // Check if user has explicit publish permission
            $publishAccess = is_numeric($workspaceRecord['publish_access'] ?? null)
                ? (int)$workspaceRecord['publish_access']
                : 0;
            // Bit 1 = "Only workspace owner can publish"
            if ($publishAccess & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE) {
                return $this->createErrorResult(
                    'You do not have publish access in this workspace. '
                    . 'Only workspace owners or admins can publish when publish restrictions are active.',
                );
            }
        }

        $filterTable = is_string($params['table'] ?? null) ? trim($params['table']) : '';
        $filterTables = [];
        if (is_array($params['tables'] ?? null)) {
            foreach ($params['tables'] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $filterTables[] = trim($entry);
                }
            }
        }
        if ($filterTable !== '') {
            $filterTables[] = $filterTable;
        }
        $filterTables = array_values(array_unique($filterTables));

        $onlyTranslations = !empty($params['onlyTranslations']);
        $dryRun = !isset($params['dryRun']) || $params['dryRun'] !== false;

        // Get the publish command map from TYPO3 core
        $cmdMap = $this->workspaceService->getCmdArrayForPublishWS($workspaceId);

        // Filter by table(s) if requested
        if ($filterTables !== [] && $cmdMap !== []) {
            $filteredMap = [];
            foreach ($filterTables as $tableName) {
                if (isset($cmdMap[$tableName])) {
                    $filteredMap[$tableName] = $cmdMap[$tableName];
                }
            }
            if ($filteredMap === []) {
                return $this->createJsonResult([
                    'workspaceId' => $workspaceId,
                    'dryRun' => $dryRun,
                    'message' => 'No pending changes found for table(s): ' . implode(', ', $filterTables) . '.',
                    'tables' => [],
                    'totalRecords' => 0,
                ]);
            }
            $cmdMap = $filteredMap;
        }

        if ($onlyTranslations) {
            $cmdMap = $this->filterCmdMapToTranslations($cmdMap);
        }

        // Build summary
        $summary = [];
        $totalRecords = 0;
        foreach ($cmdMap as $table => $records) {
            if (!is_string($table) || !is_array($records)) {
                continue;
            }
            $count = count($records);
            $summary[$table] = [
                'count' => $count,
                'uids' => array_map(intval(...), array_keys($records)),
            ];
            $totalRecords += $count;
        }

        if ($totalRecords === 0) {
            return $this->createJsonResult([
                'workspaceId' => $workspaceId,
                'dryRun' => $dryRun,
                'message' => 'No pending changes to publish.',
                'tables' => [],
                'totalRecords' => 0,
            ]);
        }

        // Dry-run: return preview only
        if ($dryRun) {
            return $this->createJsonResult([
                'workspaceId' => $workspaceId,
                'dryRun' => true,
                'message' => $totalRecords . ' record(s) across ' . count($summary)
                    . ' table(s) ready to publish. Set dryRun=false to execute.',
                'tables' => $summary,
                'totalRecords' => $totalRecords,
            ]);
        }

        // Execute publish
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();

        // Collect errors
        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = is_scalar($error) ? (string)$error : 'Unknown error';
        }

        $result = [
            'workspaceId' => $workspaceId,
            'dryRun' => false,
            'published' => true,
            'tables' => $summary,
            'totalRecords' => $totalRecords,
        ];

        if ($errors !== []) {
            $result['errors'] = $errors;
            $result['message'] = 'Publishing completed with ' . count($errors) . ' error(s).';
        } else {
            $result['message'] = 'Successfully published ' . $totalRecords . ' record(s) to live.';
        }

        return $this->createJsonResult($result);
    }

    /**
     * Reduce a publish cmdMap to entries whose record is a translation (sys_language_uid > 0).
     *
     * Tables without a languageField are dropped entirely. Within a table, only record UIDs
     * whose stored sys_language_uid is greater than zero survive the filter.
     *
     * @param array<string, mixed> $cmdMap
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function filterCmdMapToTranslations(array $cmdMap): array
    {
        $tca = $GLOBALS['TCA'] ?? null;

        $filtered = [];
        foreach ($cmdMap as $tableName => $records) {
            if (!is_string($tableName) || !is_array($records)) {
                continue;
            }
            $languageField = '';
            if (is_array($tca) && isset($tca[$tableName]) && is_array($tca[$tableName])) {
                $ctrl = $tca[$tableName]['ctrl'] ?? null;
                if (is_array($ctrl) && is_string($ctrl['languageField'] ?? null)) {
                    $languageField = (string)$ctrl['languageField'];
                }
            }
            if ($languageField === '') {
                continue;
            }

            $keep = [];
            foreach ($records as $recordUid => $cmd) {
                if (!is_numeric($recordUid) || !is_array($cmd)) {
                    continue;
                }
                $row = BackendUtility::getRecord($tableName, (int)$recordUid, $languageField);
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row[$languageField]) && (int)$row[$languageField] > 0) {
                    /** @var array<string, mixed> $cmd */
                    $keep[(int)$recordUid] = $cmd;
                }
            }

            if ($keep !== []) {
                $filtered[$tableName] = $keep;
            }
        }
        return $filtered;
    }
}
