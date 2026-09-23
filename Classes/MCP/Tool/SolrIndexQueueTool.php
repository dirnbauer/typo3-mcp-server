<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Mcp\Types\CallToolResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Discover and run EXT:solr scheduler tasks without exposing the raw scheduler CLI.
 *
 * Tasks are discovered in `tx_scheduler_task`; the only subprocess this tool
 * spawns is `scheduler:run --task=<uid> --force` for one validated Solr task.
 */
#[AdminOnly]
final class SolrIndexQueueTool extends AbstractTool
{
    private const int MAX_RUNS = 10;

    /**
     * @var list<string>
     */
    private const array SOLR_MARKERS = [
        'solr',
        'ApacheSolrForTypo3',
        'IndexQueue',
        'Index Queue',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'List or run EXT:solr scheduler tasks, especially the Apache Solr Index Queue Worker. '
                . 'Tasks are read from the TYPO3 scheduler table; the run action only invokes '
                . '`scheduler:run --task=<uid> --force` for one task that looks Solr-related and never runs all due '
                . 'scheduler tasks. Admin-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['list', 'run'],
                        'description' => 'Use "list" to discover Solr scheduler task UIDs, or "run" to execute one Solr scheduler task.',
                    ],
                    'taskUid' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Scheduler task UID for action "run". If omitted and exactly one enabled Solr task is found, that task is used.',
                    ],
                    'runs' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_RUNS,
                        'default' => 1,
                        'description' => 'How many times to run the selected Solr scheduler task. Useful for queue workers that process batches.',
                    ],
                ],
                'required' => ['action'],
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
        $action = is_string($params['action'] ?? null) ? trim($params['action']) : '';

        return match ($action) {
            'list' => $this->executeList(),
            'run' => $this->executeRun($params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Allowed: list, run']),
        };
    }

    private function executeList(): CallToolResult
    {
        $tasks = $this->discoverSolrTasks();
        if ($tasks === null) {
            return $this->schedulerUnavailableResult('list');
        }

        $payload = [
            'action' => 'list',
            'status' => 'listed',
            'tasks' => $tasks,
            'taskCount' => count($tasks),
        ];
        if ($tasks === []) {
            $payload['hint'] = 'No Solr scheduler tasks were found. Create an "Apache Solr - Index Queue Worker" task in the TYPO3 scheduler module first.';
        }

        return $this->createJsonResult($payload);
    }

    private function schedulerUnavailableResult(string $action): CallToolResult
    {
        return $this->createJsonResult([
            'action' => $action,
            'status' => 'failed',
            'tasks' => [],
            'hint' => 'The TYPO3 scheduler table is not available. Install/activate typo3/cms-scheduler and EXT:solr before using Solr indexing through MCP.',
        ], true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeRun(array $params): CallToolResult
    {
        $taskUid = $this->normalizeTaskUid($params['taskUid'] ?? null);
        $runs = $this->normalizeRuns($params['runs'] ?? null);

        $tasks = $this->discoverSolrTasks();
        if ($tasks === null) {
            return $this->schedulerUnavailableResult('run');
        }

        $selectedTask = $this->selectTask($tasks, $taskUid);
        $selectedTaskUid = $this->taskUidFromTask($selectedTask);
        $executions = [];
        $failed = false;

        for ($i = 1; $i <= $runs; $i++) {
            $execution = $this->runTypo3Command($this->buildSchedulerRunCommand($selectedTaskUid), 300);
            $execution['run'] = $i;
            $executions[] = $execution;

            if ($execution['exitCode'] !== 0) {
                $failed = true;
                break;
            }
        }

        return $this->createJsonResult([
            'action' => 'run',
            'status' => $failed ? 'failed' : 'completed',
            'task' => $selectedTask,
            'runsRequested' => $runs,
            'runsExecuted' => count($executions),
            'executions' => $executions,
        ], $failed);
    }

    /**
     * @return list<string>
     */
    private function buildSchedulerRunCommand(int $taskUid): array
    {
        return ['scheduler:run', '--task=' . $taskUid, '--force'];
    }

    private function normalizeTaskUid(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $taskUid = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $taskUid = (int)trim($value);
        } else {
            throw new ValidationException(['Parameter "taskUid" must be a positive integer.']);
        }

        if ($taskUid < 1) {
            throw new ValidationException(['Parameter "taskUid" must be a positive integer.']);
        }

        return $taskUid;
    }

    private function normalizeRuns(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        if (is_int($value)) {
            $runs = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $runs = (int)trim($value);
        } else {
            throw new ValidationException(['Parameter "runs" must be an integer between 1 and ' . self::MAX_RUNS . '.']);
        }

        if ($runs < 1 || $runs > self::MAX_RUNS) {
            throw new ValidationException(['Parameter "runs" must be between 1 and ' . self::MAX_RUNS . '.']);
        }

        return $runs;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function selectTask(array $tasks, ?int $taskUid): array
    {
        if ($taskUid !== null) {
            foreach ($tasks as $task) {
                if (($task['uid'] ?? null) !== $taskUid) {
                    continue;
                }
                if (($task['disabled'] ?? null) === true) {
                    throw new ValidationException(['Scheduler task ' . $taskUid . ' is disabled. Enable it before running it through MCP.']);
                }
                return $task;
            }

            throw new ValidationException([
                'Scheduler task ' . $taskUid . ' was not identified as an EXT:solr task. Use action "list" to get allowed Solr task UIDs.',
            ]);
        }

        $enabledTasks = array_values(array_filter(
            $tasks,
            static fn(array $task): bool => ($task['disabled'] ?? null) !== true,
        ));

        if ($enabledTasks === []) {
            throw new ValidationException(['No enabled EXT:solr scheduler task was found. Use action "list" to inspect scheduler tasks.']);
        }

        if (count($enabledTasks) > 1) {
            $uids = implode(', ', array_map(static function (array $task): string {
                $uid = $task['uid'] ?? null;
                if (is_int($uid) || is_string($uid)) {
                    return (string)$uid;
                }
                return '?';
            }, $enabledTasks));
            throw new ValidationException(['Multiple EXT:solr scheduler tasks were found (' . $uids . '). Pass taskUid explicitly.']);
        }

        return $enabledTasks[0];
    }

    /**
     * Solr-related rows of `tx_scheduler_task`; null when the table cannot be
     * queried (scheduler not installed).
     *
     * @return list<array<string, mixed>>|null
     */
    private function discoverSolrTasks(): ?array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select('uid', 'disable', 'description', 'serialized_task_object')
                ->from('tx_scheduler_task')
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return null;
        }

        $tasks = [];
        foreach ($rows as $row) {
            $serializedTask = is_string($row['serialized_task_object'] ?? null) ? $row['serialized_task_object'] : '';
            $description = is_string($row['description'] ?? null) ? trim($row['description']) : '';
            $class = $this->extractSerializedObjectClass($serializedTask);

            if (!$this->looksLikeSolrTask($class . ' ' . $description . ' ' . $serializedTask)) {
                continue;
            }

            $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
            if ($uid < 1) {
                continue;
            }

            $tasks[] = [
                'uid' => $uid,
                'disabled' => is_numeric($row['disable'] ?? null) && (int)$row['disable'] !== 0,
                'description' => $description,
                'class' => $class,
            ];
        }

        return $tasks;
    }

    private function looksLikeSolrTask(string $value): bool
    {
        return array_any(self::SOLR_MARKERS, static fn(string $marker): bool => stripos($value, $marker) !== false);
    }

    private function extractSerializedObjectClass(string $serializedTask): string
    {
        if (preg_match('/[OC]:\d+:"([^"]+)"/', $serializedTask, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function taskUidFromTask(array $task): int
    {
        $uid = $task['uid'] ?? null;
        if (is_int($uid) && $uid > 0) {
            return $uid;
        }
        if (is_string($uid) && ctype_digit($uid) && (int)$uid > 0) {
            return (int)$uid;
        }

        throw new \LogicException('Selected EXT:solr scheduler task has no valid UID.');
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function runTypo3Command(array $arguments, int $timeout): array
    {
        $typo3Binary = Environment::getProjectPath() . '/vendor/bin/typo3';
        $processArgs = [$typo3Binary, ...$arguments];

        if (!is_file($typo3Binary)) {
            return [
                'command' => $processArgs,
                'exitCode' => 127,
                'stdout' => '',
                'stderr' => 'TYPO3 CLI binary not found at: ' . $typo3Binary,
                'timedOut' => false,
                'executionTime' => 0.0,
            ];
        }

        $startTime = microtime(true);
        $process = new Process($processArgs);
        $process->setTimeout($timeout);
        $process->setWorkingDirectory(Environment::getProjectPath());

        try {
            $process->run();
            $exitCode = $process->getExitCode();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            $timedOut = !$process->isSuccessful() && $exitCode === null;
        } catch (ProcessTimedOutException $e) {
            $exitCode = 124;
            $stdout = $process->getOutput();
            $stderr = 'Command timed out: ' . $e->getMessage();
            $timedOut = true;
        } catch (\Throwable $e) {
            $exitCode = 1;
            $stdout = '';
            $stderr = 'Command execution failed: ' . $e->getMessage();
            $timedOut = false;
        }

        return [
            'command' => $processArgs,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => $timedOut,
            'executionTime' => round(microtime(true) - $startTime, 3),
        ];
    }
}
