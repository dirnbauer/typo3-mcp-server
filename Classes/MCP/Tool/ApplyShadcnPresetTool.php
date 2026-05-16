<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Apply a shadcn/ui preset to an existing frontend project.
 */
#[AdminOnly]
final class ApplyShadcnPresetTool extends AbstractTool
{
    private const PRESET_PATTERN = '/^[A-Za-z0-9_-]{2,128}$/';

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Apply a shadcn/ui preset to an existing frontend project using `shadcn apply --preset`. '
                . 'Accepts preset codes from https://ui.shadcn.com/create, such as "b0" or "bkqYkPSa0", '
                . 'or the full create URL. Admin-only because it rewrites project frontend files.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'preset' => [
                        'type' => 'string',
                        'description' => 'shadcn preset code or https://ui.shadcn.com/create?preset=... URL. Examples: "b0", "bkqYkPSa0".',
                    ],
                    'only' => [
                        'oneOf' => [
                            [
                                'type' => 'string',
                                'enum' => ['theme', 'font', 'theme,font', 'font,theme'],
                            ],
                            [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                    'enum' => ['theme', 'font'],
                                ],
                                'uniqueItems' => true,
                            ],
                        ],
                        'description' => 'Optional partial apply. Omit for the full preset, or pass "theme", "font", or both.',
                    ],
                    'cwd' => [
                        'type' => 'string',
                        'description' => 'Optional project-root-relative working directory for monorepos. Defaults to the TYPO3 project root.',
                    ],
                    'packageManager' => [
                        'type' => 'string',
                        'enum' => ['auto', 'npx', 'pnpm', 'yarn', 'bun'],
                        'description' => 'Package runner to use. "auto" detects lock files; default is auto.',
                    ],
                ],
                'required' => ['preset'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $preset = $this->normalizePreset(is_string($params['preset'] ?? null) ? $params['preset'] : '');
        $workingDirectory = $this->resolveWorkingDirectory(is_string($params['cwd'] ?? null) ? $params['cwd'] : '');
        $only = $this->normalizeOnly($params['only'] ?? null);
        $packageManager = $this->normalizePackageManager(is_string($params['packageManager'] ?? null) ? $params['packageManager'] : 'auto', $workingDirectory);

        $processArgs = [
            ...$this->runnerArgs($packageManager),
            'shadcn@latest',
            'apply',
            '--preset',
            $preset,
            '--yes',
        ];

        if ($only !== []) {
            $processArgs[] = '--only';
            $processArgs[] = implode(',', $only);
        }

        $startTime = microtime(true);
        $process = new Process($processArgs);
        $process->setTimeout(300);
        $process->setWorkingDirectory($workingDirectory);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return $this->createErrorResult('shadcn preset apply failed to start: ' . $e->getMessage());
        }

        $result = [
            'status' => $process->getExitCode() === 0 ? 'applied' : 'failed',
            'preset' => $preset,
            'only' => $only,
            'packageManager' => $packageManager,
            'cwd' => $workingDirectory,
            'command' => $processArgs,
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'timedOut' => !$process->isSuccessful() && $process->getExitCode() === null,
            'executionTime' => round(microtime(true) - $startTime, 3),
        ];

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = $encoded !== false ? $encoded : '{}';
        return new CallToolResult([new TextContent($json)], $process->getExitCode() !== 0);
    }

    private function normalizePreset(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new ValidationException(['Parameter "preset" is required.']);
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $parts = parse_url($value);
            if (!is_array($parts)) {
                throw new ValidationException(['Invalid shadcn preset URL. Use https://ui.shadcn.com/create?preset=...']);
            }
            $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
            $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
            if ($host !== 'ui.shadcn.com' || $path !== '/create') {
                throw new ValidationException(['Invalid shadcn preset URL. Use https://ui.shadcn.com/create?preset=...']);
            }
            parse_str(is_string($parts['query'] ?? null) ? $parts['query'] : '', $query);
            $value = is_string($query['preset'] ?? null) ? trim($query['preset']) : '';
        }

        if (preg_match(self::PRESET_PATTERN, $value) !== 1) {
            throw new ValidationException(['Invalid shadcn preset "' . $value . '". Use a preset code from https://ui.shadcn.com/create, e.g. "b0" or "bkqYkPSa0".']);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeOnly(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = [];
        if (is_string($value)) {
            $parts = array_map(trim(...), explode(',', $value));
        } elseif (is_array($value)) {
            $parts = array_map(static fn(mixed $part): string => is_string($part) ? trim($part) : '', $value);
        } else {
            throw new ValidationException(['Parameter "only" must be "theme", "font", or an array of those values.']);
        }

        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!in_array($part, ['theme', 'font'], true)) {
                throw new ValidationException(['Invalid "only" value "' . $part . '". Allowed values: theme, font.']);
            }
            if (!in_array($part, $normalized, true)) {
                $normalized[] = $part;
            }
        }

        return $normalized;
    }

    private function resolveWorkingDirectory(string $cwd): string
    {
        $projectPath = realpath(Environment::getProjectPath());
        if ($projectPath === false) {
            throw new ValidationException(['TYPO3 project path could not be resolved.']);
        }

        if ($cwd === '') {
            return $projectPath;
        }

        if (str_starts_with($cwd, '/') || str_contains($cwd, "\0")) {
            throw new ValidationException(['Parameter "cwd" must be a project-root-relative path.']);
        }

        $resolved = realpath($projectPath . DIRECTORY_SEPARATOR . $cwd);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ValidationException(['Working directory "' . $cwd . '" does not exist under the TYPO3 project root.']);
        }

        if ($resolved !== $projectPath && !str_starts_with($resolved, $projectPath . DIRECTORY_SEPARATOR)) {
            throw new ValidationException(['Working directory "' . $cwd . '" must stay inside the TYPO3 project root.']);
        }

        return $resolved;
    }

    private function normalizePackageManager(string $packageManager, string $workingDirectory): string
    {
        $packageManager = $packageManager === '' ? 'auto' : $packageManager;
        if (!in_array($packageManager, ['auto', 'npx', 'pnpm', 'yarn', 'bun'], true)) {
            throw new ValidationException(['Invalid packageManager "' . $packageManager . '". Allowed: auto, npx, pnpm, yarn, bun.']);
        }

        if ($packageManager !== 'auto') {
            return $packageManager;
        }

        if (file_exists($workingDirectory . '/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (file_exists($workingDirectory . '/yarn.lock')) {
            return 'yarn';
        }
        if (file_exists($workingDirectory . '/bun.lock') || file_exists($workingDirectory . '/bun.lockb')) {
            return 'bun';
        }

        return 'npx';
    }

    /**
     * @return list<string>
     */
    private function runnerArgs(string $packageManager): array
    {
        return match ($packageManager) {
            'pnpm' => ['pnpm', 'dlx'],
            'yarn' => ['yarn', 'dlx'],
            'bun' => ['bunx'],
            default => ['npx'],
        };
    }
}
