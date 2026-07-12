<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;
use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry for MCP tools
 */
final class ToolRegistry
{
    /**
     * @var ToolInterface[] Registered tools
     */
    private array $tools = [];

    /**
     * @param iterable<mixed> $tools
     */
    public function __construct(
        #[AutowireIterator('mcp.tool')]
        iterable $tools,
        private readonly ?CapabilityManifestService $capabilityManifest = null,
        private readonly ?DevSiteToolService $devSiteToolService = null,
    ) {
        foreach ($tools as $tool) {
            $normalizedTool = $this->normalizeTool($tool);
            if ($normalizedTool === null) {
                continue;
            }

            $name = $normalizedTool->getName();
            if (isset($this->tools[$name])) {
                throw new \LogicException('Duplicate MCP tool name: ' . $name);
            }
            $this->tools[$name] = $normalizedTool;
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
        return $this->tools[$name] ?? null;
    }

    public function getCapabilityManifest(): ?CapabilityManifestService
    {
        return $this->capabilityManifest;
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
