<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Execute a whitelisted subset of TYPO3 CLI commands.
 */
final class SafeCliTool extends AbstractTool
{
    /**
     * Allowed commands with their configuration.
     *
     * @var array<string, array{description: string, allowedArgs: list<string>, timeout: int}>
     */
    private const ALLOWED_COMMANDS = [
        'cache:flush' => [
            'description' => 'Flush all caches or a specific cache group',
            'allowedArgs' => ['--group'],
            'timeout' => 60,
        ],
        'cache:warmup' => [
            'description' => 'Warm up caches after flushing',
            'allowedArgs' => [],
            'timeout' => 120,
        ],
        'referenceindex:update' => [
            'description' => 'Update the reference index (repairs broken relations)',
            'allowedArgs' => [],
            'timeout' => 300,
        ],
        'extension:list' => [
            'description' => 'List installed extensions and their status',
            'allowedArgs' => [],
            'timeout' => 30,
        ],
        'site:list' => [
            'description' => 'List configured sites with their base URLs',
            'allowedArgs' => [],
            'timeout' => 30,
        ],
        'site:show' => [
            'description' => 'Show configuration details for a specific site',
            'allowedArgs' => [],
            'timeout' => 30,
        ],
    ];

    /**
     * Characters that indicate potential shell injection.
     */
    private const DANGEROUS_CHARACTERS = [';', '|', '&', '$', '`', '(', ')', '{', '}', '<', '>', "\n", "\r", "\0"];

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        $commandDescriptions = [];
        foreach (self::ALLOWED_COMMANDS as $cmd => $config) {
            $argsInfo = $config['allowedArgs'] !== [] ? ' (args: ' . implode(', ', $config['allowedArgs']) . ')' : '';
            $commandDescriptions[] = $cmd . ': ' . $config['description'] . $argsInfo;
        }

        return [
            'description' => 'Execute a whitelisted TYPO3 CLI command for maintenance and diagnostics. '
                . 'Only safe commands are allowed. Available commands: '
                . implode('; ', array_keys(self::ALLOWED_COMMANDS)),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => "TYPO3 CLI command to execute.\n" . implode("\n", $commandDescriptions),
                        'enum' => array_keys(self::ALLOWED_COMMANDS),
                    ],
                    'arguments' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional command arguments. Only allowed arguments per command are accepted. '
                            . 'Example for cache:flush: ["--group", "pages"]',
                    ],
                ],
                'required' => ['command'],
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
        $command = is_string($params['command'] ?? null) ? $params['command'] : '';

        if (!isset(self::ALLOWED_COMMANDS[$command])) {
            throw new ValidationException([
                'Command "' . $command . '" is not allowed. Allowed commands: ' . implode(', ', array_keys(self::ALLOWED_COMMANDS)),
            ]);
        }

        $config = self::ALLOWED_COMMANDS[$command];
        $arguments = [];

        if (is_array($params['arguments'] ?? null)) {
            foreach ($params['arguments'] as $arg) {
                if (!is_string($arg)) {
                    throw new ValidationException(['All arguments must be strings']);
                }
                $arguments[] = $arg;
            }
        }

        // Validate arguments
        $this->validateArguments($command, $arguments, $config['allowedArgs']);

        // Build the command
        $typo3Binary = Environment::getProjectPath() . '/vendor/bin/typo3';
        if (!file_exists($typo3Binary)) {
            return $this->createErrorResult('TYPO3 CLI binary not found at: ' . $typo3Binary);
        }

        $processArgs = [$typo3Binary, $command, ...$arguments];

        $startTime = microtime(true);
        $process = new Process($processArgs);
        $process->setTimeout($config['timeout']);
        $process->setWorkingDirectory(Environment::getProjectPath());

        try {
            $process->run();
        } catch (\Throwable $e) {
            return $this->createErrorResult('Command execution failed: ' . $e->getMessage());
        }

        $executionTime = round(microtime(true) - $startTime, 3);

        $result = [
            'command' => $command,
            'arguments' => $arguments,
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'timedOut' => !$process->isSuccessful() && $process->getExitCode() === null,
            'executionTime' => $executionTime,
        ];

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)], $process->getExitCode() !== 0);
    }

    /**
     * Validate that all arguments are allowed for the given command.
     *
     * @param list<string> $arguments
     * @param list<string> $allowedArgs
     */
    private function validateArguments(string $command, array $arguments, array $allowedArgs): void
    {
        foreach ($arguments as $arg) {
            // Check for dangerous characters
            foreach (self::DANGEROUS_CHARACTERS as $char) {
                if (str_contains($arg, $char)) {
                    throw new ValidationException([
                        'Argument contains forbidden characters: "' . $arg . '"',
                    ]);
                }
            }

            // Check that option-style arguments are in the allowlist
            if (str_starts_with($arg, '-')) {
                $optionName = $arg;
                // Strip value from --option=value
                if (str_contains($optionName, '=')) {
                    $optionName = substr($optionName, 0, (int)strpos($optionName, '='));
                }

                if (!in_array($optionName, $allowedArgs, true)) {
                    throw new ValidationException([
                        'Argument "' . $optionName . '" is not allowed for command "' . $command . '". '
                        . ($allowedArgs !== [] ? 'Allowed: ' . implode(', ', $allowedArgs) : 'This command accepts no options.'),
                    ]);
                }
            }
        }
    }
}
