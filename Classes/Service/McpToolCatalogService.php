<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Server\McpServerException;
use Mcp\Types\CallToolResult;

/** Shared application service used by MCP, Abilities, CLI, and REST projections. */
final readonly class McpToolCatalogService
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private ToolResultNormalizer $resultNormalizer,
    ) {}

    /**
     * @return list<array{name: string, schema: array<string, mixed>}>
     */
    public function list(): array
    {
        $tools = $this->toolRegistry->getTools();
        ksort($tools, SORT_STRING);
        $catalog = [];
        foreach ($tools as $tool) {
            $catalog[] = ['name' => $tool->getName(), 'schema' => $tool->getSchema()];
        }
        return $catalog;
    }

    /**
     * @return array{name: string, schema: array<string, mixed>}
     */
    public function describe(string $name): array
    {
        $tool = $this->toolRegistry->getTool($name);
        if ($tool === null) {
            throw McpServerException::unknownTool($name);
        }

        return ['name' => $tool->getName(), 'schema' => $tool->getSchema()];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments): CallToolResult
    {
        $tool = $this->toolRegistry->getTool($name);
        if ($tool === null) {
            throw McpServerException::unknownTool($name);
        }

        return $this->resultNormalizer->normalize($tool->execute($arguments));
    }
}
