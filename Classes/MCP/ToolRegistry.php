<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;
use Hn\McpServer\MCP\Tool\ToolInterface;
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
    ) {
        foreach ($tools as $tool) {
            $normalizedTool = $this->normalizeTool($tool);
            if ($normalizedTool === null) {
                continue;
            }

            $this->tools[$normalizedTool->getName()] = $normalizedTool;
        }
    }

    /**
     * Get all registered tools
     *
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Get a specific tool by name
     */
    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    private function normalizeTool(mixed $tool): ?ToolInterface
    {
        if ($tool instanceof ToolInterface) {
            return $tool;
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
