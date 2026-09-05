<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Package\PackageManager;

/** Inspect the effective PSR-15 request stacks and their declarations. */
#[DevSiteOnly]
final class MiddlewareStackTool extends AbstractTool
{
    private const STACKS = ['frontend', 'backend', 'core'];

    public function __construct(
        private readonly MiddlewareStackResolver $middlewareStackResolver,
        private readonly PackageManager $packageManager,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'List an effective TYPO3 PSR-15 middleware stack in execution order, including package and ordering constraints.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'stack' => [
                        'type' => 'string',
                        'enum' => self::STACKS,
                        'default' => 'frontend',
                        'description' => 'Request stack to inspect.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive identifier or class substring.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $stack = is_string($params['stack'] ?? null) ? $params['stack'] : 'frontend';
        if (!in_array($stack, self::STACKS, true)) {
            throw new ValidationException(['stack must be one of: frontend, backend, core.']);
        }
        $search = is_string($params['search'] ?? null) ? strtolower(trim($params['search'])) : '';
        $declarations = $this->collectDeclarations($stack);

        try {
            $resolved = array_reverse(iterator_to_array($this->middlewareStackResolver->resolve($stack)), true);
        } catch (\Throwable $exception) {
            throw new ValidationException([
                sprintf('Middleware stack "%s" could not be resolved: %s', $stack, $exception->getMessage()),
            ]);
        }

        $middlewares = [];
        $position = 0;
        foreach ($resolved as $identifier => $className) {
            ++$position;
            $identifier = (string)$identifier;
            if (!is_string($className)) {
                continue;
            }
            if ($search !== ''
                && !str_contains(strtolower($identifier), $search)
                && !str_contains(strtolower($className), $search)
            ) {
                continue;
            }

            $declaration = $declarations[$identifier] ?? [];
            $middlewares[] = array_filter([
                'position' => $position,
                'identifier' => $identifier,
                'class' => $className,
                'package' => $declaration['package'] ?? null,
                'before' => $declaration['before'] ?? null,
                'after' => $declaration['after'] ?? null,
            ], static fn(mixed $value): bool => $value !== null);
        }

        $disabled = [];
        foreach ($declarations as $identifier => $declaration) {
            if (($declaration['disabled'] ?? false) === true) {
                $disabled[] = $identifier;
            }
        }

        $payload = [
            'stack' => $stack,
            'resolvedCount' => count($resolved),
            'matchCount' => count($middlewares),
            'middlewares' => $middlewares,
            'disabled' => $disabled,
            'hint' => 'Position 1 sees the request first and the response last.',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }

    /** @return array<string, array<string, mixed>> */
    private function collectDeclarations(string $stack): array
    {
        $declarations = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $file = $package->getPackagePath() . 'Configuration/RequestMiddlewares.php';
            if (!is_file($file)) {
                continue;
            }

            $configuration = include $file;
            if (!is_array($configuration) || !is_array($configuration[$stack] ?? null)) {
                continue;
            }

            foreach ($configuration[$stack] as $identifier => $middleware) {
                if (!is_array($middleware)) {
                    continue;
                }
                $declarations[(string)$identifier] = array_filter([
                    'package' => $package->getPackageKey(),
                    'before' => ($middleware['before'] ?? []) !== [] ? $middleware['before'] : null,
                    'after' => ($middleware['after'] ?? []) !== [] ? $middleware['after'] : null,
                    'disabled' => ($middleware['disabled'] ?? false) === true ? true : null,
                ], static fn(mixed $value): bool => $value !== null);
            }
        }

        return $declarations;
    }
}
