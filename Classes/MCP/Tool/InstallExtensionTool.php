<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Install, activate, or search for TYPO3 extensions via Composer and TYPO3 CLI.
 *
 * Admin-only tool that provides three actions:
 * - require: install a Composer package (composer require)
 * - activate: activate an installed TYPO3 extension (extension:activate)
 * - search: search for TYPO3 extensions on Packagist (composer search)
 */
#[AdminOnly]
final class InstallExtensionTool extends AbstractTool
{
    /**
     * Characters that indicate potential shell injection.
     */
    private const DANGEROUS_CHARACTERS = [';', '|', '&', '$', '`', '(', ')', '{', '}', '<', '>', "\n", "\r", "\0"];

    /**
     * Valid Composer package name pattern (vendor/package).
     */
    private const PACKAGE_NAME_PATTERN = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?\/[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/';

    /**
     * Valid TYPO3 extension key pattern.
     */
    private const EXTENSION_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Install, activate, or search for TYPO3 extensions. '
                . 'Actions: "require" installs a Composer package, "activate" enables an installed extension, '
                . '"search" finds TYPO3 extensions on Packagist, "list" returns loaded extensions with version and state. Admin-only.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "require" (composer require), "activate" (extension:activate), "search" (composer search), or "list" (loaded extensions)',
                        'enum' => ['require', 'activate', 'search', 'list'],
                    ],
                    'package' => [
                        'type' => 'string',
                        'description' => 'Composer package name (vendor/package) for "require" action. Example: "georgringer/news"',
                    ],
                    'key' => [
                        'type' => 'string',
                        'description' => 'TYPO3 extension key for "activate" action. Example: "news"',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query for "search" action. Example: "news"',
                    ],
                ],
                'required' => ['action'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $action = is_string($params['action'] ?? null) ? $params['action'] : '';

        return match ($action) {
            'require' => $this->executeRequire($params),
            'activate' => $this->executeActivate($params),
            'search' => $this->executeSearch($params),
            'list' => $this->executeList(),
            default => throw new ValidationException(['Unknown action "' . $action . '". Allowed: require, activate, search, list']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeRequire(array $params): CallToolResult
    {
        $package = is_string($params['package'] ?? null) ? $params['package'] : '';

        if ($package === '') {
            throw new ValidationException(['Parameter "package" is required for action "require"']);
        }

        $this->validateSafeString($package, 'package');

        if (!preg_match(self::PACKAGE_NAME_PATTERN, $package)) {
            throw new ValidationException([
                'Invalid package name "' . $package . '". Must match vendor/package format (lowercase, alphanumeric, dots, hyphens, underscores).',
            ]);
        }

        $composerBinary = $this->findComposerBinary();
        $processArgs = [$composerBinary, 'require', $package, '--no-interaction', '--no-scripts'];

        $result = $this->runProcess($processArgs, 300);
        $result['action'] = 'require';
        $result['package'] = $package;

        if ($result['exitCode'] === 0) {
            $result['hint'] = 'Package installed successfully. You may need to run extension:activate to enable it. '
                . 'Use action "activate" with the extension key.';
        }

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)], $result['exitCode'] !== 0);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeActivate(array $params): CallToolResult
    {
        $key = is_string($params['key'] ?? null) ? $params['key'] : '';

        if ($key === '') {
            throw new ValidationException(['Parameter "key" is required for action "activate"']);
        }

        $this->validateSafeString($key, 'key');

        if (!preg_match(self::EXTENSION_KEY_PATTERN, $key)) {
            throw new ValidationException([
                'Invalid extension key "' . $key . '". Must be lowercase letters, digits, and underscores, starting with a letter.',
            ]);
        }

        $typo3Binary = Environment::getProjectPath() . '/vendor/bin/typo3';
        if (!file_exists($typo3Binary)) {
            return $this->createErrorResult('TYPO3 CLI binary not found at: ' . $typo3Binary);
        }

        $processArgs = [$typo3Binary, 'extension:activate', $key];

        $result = $this->runProcess($processArgs, 60);
        $result['action'] = 'activate';
        $result['key'] = $key;

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)], $result['exitCode'] !== 0);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeSearch(array $params): CallToolResult
    {
        $query = is_string($params['query'] ?? null) ? $params['query'] : '';

        if ($query === '') {
            throw new ValidationException(['Parameter "query" is required for action "search"']);
        }

        $this->validateSafeString($query, 'query');

        $composerBinary = $this->findComposerBinary();
        $processArgs = [$composerBinary, 'search', '--type=typo3-cms-extension', $query];

        $result = $this->runProcess($processArgs, 30);
        $result['action'] = 'search';
        $result['query'] = $query;

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)], $result['exitCode'] !== 0);
    }

    private function executeList(): CallToolResult
    {
        $packageManager = GeneralUtility::makeInstance(PackageManager::class);
        /** @var list<array{key: string, version: string, package: string, state: string}> $extensions */
        $extensions = [];

        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extensionKey) {
            if (!is_string($extensionKey)) {
                continue;
            }
            $package = $packageManager->getPackage($extensionKey);
            $extensions[] = [
                'key' => $extensionKey,
                'version' => $package->getValueFromComposerManifest('version') ?? '',
                'package' => $package->getValueFromComposerManifest('name') ?? '',
                'state' => 'active',
            ];
        }

        usort($extensions, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        $json = json_encode([
            'action' => 'list',
            'total' => count($extensions),
            'extensions' => $extensions,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '{}';
        }

        return new CallToolResult([new TextContent($json)]);
    }

    /**
     * Validate a string does not contain dangerous characters.
     */
    private function validateSafeString(string $value, string $paramName): void
    {
        foreach (self::DANGEROUS_CHARACTERS as $char) {
            if (str_contains($value, $char)) {
                throw new ValidationException([
                    'Parameter "' . $paramName . '" contains forbidden characters.',
                ]);
            }
        }
    }

    /**
     * Find the Composer binary.
     */
    private function findComposerBinary(): string
    {
        // Try common locations
        $candidates = [
            Environment::getProjectPath() . '/composer.phar',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Fall back to system composer
        return 'composer';
    }

    /**
     * Run a process and return the result as an array.
     *
     * @param list<string> $processArgs
     * @return array{exitCode: int|null, stdout: string, stderr: string, timedOut: bool, executionTime: float}
     */
    private function runProcess(array $processArgs, int $timeout): array
    {
        $startTime = microtime(true);
        $process = new Process($processArgs);
        $process->setTimeout($timeout);
        $process->setWorkingDirectory(Environment::getProjectPath());

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [
                'exitCode' => -1,
                'stdout' => '',
                'stderr' => 'Command execution failed: ' . $e->getMessage(),
                'timedOut' => false,
                'executionTime' => round(microtime(true) - $startTime, 3),
            ];
        }

        return [
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'timedOut' => !$process->isSuccessful() && $process->getExitCode() === null,
            'executionTime' => round(microtime(true) - $startTime, 3),
        ];
    }
}
