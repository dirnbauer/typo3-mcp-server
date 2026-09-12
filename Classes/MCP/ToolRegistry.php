<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;
use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\Tool\ToolProviderInterface;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry for MCP tools.
 *
 * Native tools arrive eagerly through the `mcp.tool` tag. Tool providers
 * (`mcp.tool_provider`, e.g. the abilities bridge) are consulted lazily on
 * the first catalog access: their tools may depend on services that in turn
 * depend on this registry, which a constructor-time resolution would turn
 * into a circular reference.
 */
final class ToolRegistry
{
    /**
     * @var ToolInterface[] Registered tools, keyed by name
     */
    private array $tools = [];

    private bool $providersResolved = false;

    /**
     * @param iterable<mixed> $tools
     * @param iterable<mixed> $toolProviders
     */
    public function __construct(
        #[AutowireIterator('mcp.tool')]
        iterable $tools,
        private readonly ?CapabilityManifestService $capabilityManifest = null,
        private readonly ?DevSiteToolService $devSiteToolService = null,
        #[AutowireIterator('mcp.tool_provider')]
        private readonly iterable $toolProviders = [],
    ) {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
        ksort($this->tools, SORT_STRING);
    }

    /**
     * Get all registered tools
     *
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        $this->resolveProviders();

        if ($this->devSiteToolService === null || $this->devSiteToolService->isAvailable()) {
            return $this->tools;
        }

        return array_filter(
            $this->tools,
            static fn(ToolInterface $tool): bool => !DevSiteToolService::hasDevSiteOnlyAttribute($tool),
        );
    }

    /**
     * Get a specific tool by name. Capability-manifest enforcement happens
     * inside AbstractTool::execute() so a manifest-blocked call surfaces a
     * structured error instead of a silent "tool not found".
     */
    public function getTool(string $name): ?ToolInterface
    {
        $this->resolveProviders();

        return $this->tools[$name] ?? null;
    }

    public function getCapabilityManifest(): ?CapabilityManifestService
    {
        return $this->capabilityManifest;
    }

    private function resolveProviders(): void
    {
        if ($this->providersResolved) {
            return;
        }
        // Flagged before iterating: a provider that consults the registry
        // while it is being built sees the native tools and nothing else.
        $this->providersResolved = true;

        foreach ($this->toolProviders as $provider) {
            if (!$provider instanceof ToolProviderInterface) {
                continue;
            }
            foreach ($provider->getTools() as $tool) {
                $this->register($tool);
            }
        }
        ksort($this->tools, SORT_STRING);
    }

    private function register(mixed $tool): void
    {
        $normalizedTool = $this->normalizeTool($tool);
        if ($normalizedTool === null) {
            return;
        }

        $name = $normalizedTool->getName();
        if (isset($this->tools[$name])) {
            throw new \LogicException('Duplicate MCP tool name: ' . $name);
        }
        $this->tools[$name] = $normalizedTool;
    }

    private function normalizeTool(mixed $tool): ?ToolInterface
    {
        if ($tool instanceof AbstractTool) {
            return $tool;
        }

        // Capability-manifest, admin-only, and dev-site-only enforcement live
        // in AbstractTool::execute(). A third-party service implementing the
        // interface directly must therefore still pass through the adapter.
        if ($tool instanceof ToolInterface) {
            return new CompatibleToolAdapter($tool);
        }

        if (!is_object($tool)) {
            return null;
        }

        if (!method_exists($tool, 'getName') || !method_exists($tool, 'execute')) {
            return null;
        }

        return new CompatibleToolAdapter($tool);
    }
}
