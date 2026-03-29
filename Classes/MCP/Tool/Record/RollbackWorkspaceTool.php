<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Discard (rollback) pending workspace changes.
 *
 * Uses TYPO3's native WorkspaceService to discover changed records and
 * DataHandler with the clearWSID action to discard them. Defaults to
 * dry-run mode for safety.
 */
final class RollbackWorkspaceTool extends AbstractRecordTool
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
            'description' => 'Discard (rollback) pending workspace changes. '
                . 'Defaults to dry-run mode (dryRun=true) which shows what would be discarded without executing. '
                . 'Set dryRun=false to actually discard changes. Use WorkspaceReview first to inspect changes. '
                . 'Discarding is irreversible — all uncommitted workspace modifications are permanently lost.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: only discard changes for this specific table',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Optional: only discard changes for this specific record UID (requires table)',
                    ],
                    'dryRun' => [
                        'type' => 'boolean',
                        'description' => 'If true (default), preview what would be discarded without executing. '
                            . 'Set to false to actually discard workspace changes.',
                        'default' => true,
                    ],
                ],
                'required' => [],
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

        $workspaceId = $backendUser->workspace ?? 0;
        if ($workspaceId === 0) {
            return $this->createErrorResult(
                'Cannot discard changes in the live workspace. Switch to a draft workspace first or provide workspace_id.',
            );
        }

        // Validate workspace access
        $workspaceRecord = $backendUser->checkWorkspace($workspaceId);
        if (!\is_array($workspaceRecord) || !isset($workspaceRecord['_ACCESS'])) {
            return $this->createErrorResult('Cannot access workspace #' . $workspaceId . '.');
        }

        $filterTable = \is_string($params['table'] ?? null) ? trim($params['table']) : '';
        $filterUid = isset($params['uid']) && is_numeric($params['uid']) ? (int)$params['uid'] : 0;
        $dryRun = !isset($params['dryRun']) || $params['dryRun'] !== false;

        if ($filterUid > 0 && $filterTable === '') {
            return $this->createErrorResult('The "uid" parameter requires "table" to be set as well.');
        }

        // Get the publish command map from TYPO3 core — it lists all changed records
        $cmdMap = $this->workspaceService->getCmdArrayForPublishWS($workspaceId);

        // Filter by table if requested
        if ($filterTable !== '' && $cmdMap !== []) {
            if (!isset($cmdMap[$filterTable])) {
                return $this->createJsonResult([
                    'workspaceId' => $workspaceId,
                    'dryRun' => $dryRun,
                    'message' => 'No pending changes found for table "' . $filterTable . '".',
                    'tables' => [],
                    'totalRecords' => 0,
                ]);
            }
            $cmdMap = [$filterTable => $cmdMap[$filterTable]];
        }

        // Filter by uid if requested
        if ($filterUid > 0 && $filterTable !== '' && isset($cmdMap[$filterTable])) {
            if (!isset($cmdMap[$filterTable][$filterUid])) {
                return $this->createJsonResult([
                    'workspaceId' => $workspaceId,
                    'dryRun' => $dryRun,
                    'message' => 'No pending changes found for ' . $filterTable . ':' . $filterUid . '.',
                    'tables' => [],
                    'totalRecords' => 0,
                ]);
            }
            $cmdMap = [$filterTable => [$filterUid => $cmdMap[$filterTable][$filterUid]]];
        }

        // Rewrite the command map: replace "publish" with "clearWSID" to discard
        $discardCmdMap = [];
        foreach ($cmdMap as $table => $records) {
            if (!\is_string($table) || !\is_array($records)) {
                continue;
            }
            foreach ($records as $uid => $command) {
                $discardCmdMap[$table][$uid]['version']['action'] = 'clearWSID';
            }
        }

        // Build summary
        $summary = [];
        $totalRecords = 0;
        foreach ($discardCmdMap as $table => $records) {
            $count = \count($records);
            $summary[$table] = [
                'count' => $count,
                'uids' => array_map('intval', array_keys($records)),
            ];
            $totalRecords += $count;
        }

        if ($totalRecords === 0) {
            return $this->createJsonResult([
                'workspaceId' => $workspaceId,
                'dryRun' => $dryRun,
                'message' => 'No pending changes to discard.',
                'tables' => [],
                'totalRecords' => 0,
            ]);
        }

        // Dry-run: return preview only
        if ($dryRun) {
            return $this->createJsonResult([
                'workspaceId' => $workspaceId,
                'dryRun' => true,
                'message' => $totalRecords . ' record(s) across ' . \count($summary)
                    . ' table(s) would be discarded. Set dryRun=false to execute.',
                'tables' => $summary,
                'totalRecords' => $totalRecords,
            ]);
        }

        // Execute discard
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start([], $discardCmdMap);
        $dataHandler->process_cmdmap();

        // Collect errors
        $errors = [];
        foreach ($dataHandler->errorLog as $error) {
            $errors[] = \is_scalar($error) ? (string)$error : 'Unknown error';
        }

        $result = [
            'workspaceId' => $workspaceId,
            'dryRun' => false,
            'discarded' => true,
            'tables' => $summary,
            'totalRecords' => $totalRecords,
        ];

        if ($errors !== []) {
            $result['errors'] = $errors;
            $result['message'] = 'Discard completed with ' . \count($errors) . ' error(s).';
        } else {
            $result['message'] = 'Successfully discarded ' . $totalRecords . ' record(s) from workspace.';
        }

        return $this->createJsonResult($result);
    }
}
