<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Adapts tagged third-party MCP tools to the native ToolInterface.
 */
final class CompatibleToolAdapter extends AbstractTool
{
    public function __construct(
        private readonly object $tool,
    ) {}

    public function getName(): string
    {
        return $this->callStringMethod('getName', (new \ReflectionClass($this->tool))->getShortName());
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        $schema = method_exists($this->tool, 'getSchema')
            ? $this->tool->getSchema()
            : (method_exists($this->tool, 'getInputSchema') ? $this->tool->getInputSchema() : []);

        return $this->normalizeSchema($schema);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        /** @var callable(array<string, mixed>): mixed $executor */
        $executor = [$this->tool, 'execute'];
        $result = $executor($params);

        if ($result instanceof CallToolResult) {
            return $result;
        }

        return new CallToolResult([new TextContent($this->normalizeResultText($result))], false);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSchema(mixed $schema): array
    {
        $description = $this->callStringMethod('getDescription');
        if (!is_array($schema)) {
            return [
                'description' => $description,
                'inputSchema' => $this->createDefaultInputSchema(),
            ];
        }

        if (isset($schema['inputSchema']) && is_array($schema['inputSchema'])) {
            if (!isset($schema['description']) || !is_string($schema['description'])) {
                $schema['description'] = $description;
            }
            return $schema;
        }

        return [
            'description' => $description,
            'inputSchema' => $this->normalizeInputSchema($schema),
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeInputSchema(array $schema): array
    {
        if (!isset($schema['type']) && !isset($schema['properties']) && !isset($schema['required'])) {
            return $this->createDefaultInputSchema();
        }

        if (!isset($schema['type']) || !is_string($schema['type'])) {
            $schema['type'] = 'object';
        }

        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            $schema['properties'] = [];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function createDefaultInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    private function normalizeResultText(mixed $result): string
    {
        if ($result instanceof TextContent) {
            return $result->text;
        }

        if (is_string($result)) {
            return $result;
        }

        if (is_scalar($result) || $result === null) {
            return (string)$result;
        }

        if (is_array($result) || $result instanceof \JsonSerializable) {
            $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                return $encoded;
            }
        }

        if ($result instanceof \Stringable) {
            return (string)$result;
        }

        return sprintf('Unsupported tool result type: %s', get_debug_type($result));
    }

    private function callStringMethod(string $method, string $fallback = ''): string
    {
        if (!method_exists($this->tool, $method)) {
            return $fallback;
        }

        $value = $this->tool->{$method}();

        return is_string($value) ? $value : $fallback;
    }
}
