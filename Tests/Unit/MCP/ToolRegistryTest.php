<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testRegistryKeepsNativeToolsUntouched(): void
    {
        $tool = new class () implements ToolInterface {
            public function getName(): string
            {
                return 'NativeTool';
            }

            public function getSchema(): array
            {
                return [
                    'description' => 'Native tool',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ];
            }

            public function execute(array $params): CallToolResult
            {
                return new CallToolResult([], false);
            }
        };

        $registry = new ToolRegistry([$tool]);

        self::assertSame($tool, $registry->getTool('NativeTool'));
    }

    public function testRegistryAdaptsLegacyTaggedToolWithoutGetSchema(): void
    {
        $legacyTool = new class () {
            public function getName(): string
            {
                return 'LegacyTool';
            }

            public function getDescription(): string
            {
                return 'Legacy tool description';
            }

            /**
             * @return array<string, mixed>
             */
            public function getInputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'value' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => ['value'],
                ];
            }

            /**
             * @param array<string, mixed> $params
             */
            public function execute(array $params): string
            {
                return json_encode(['value' => $params['value'] ?? null], JSON_THROW_ON_ERROR);
            }
        };

        $registry = new ToolRegistry([$legacyTool]);
        $tool = $registry->getTool('LegacyTool');

        self::assertNotNull($tool);
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertNotSame($legacyTool, $tool);

        $schema = $tool->getSchema();
        self::assertIsArray($schema['inputSchema']);
        self::assertIsArray($schema['inputSchema']['properties']);
        self::assertSame('Legacy tool description', $schema['description']);
        self::assertSame(['value'], $schema['inputSchema']['required']);
        self::assertArrayHasKey('value', $schema['inputSchema']['properties']);

        $result = $tool->execute(['value' => 'ok']);
        self::assertFalse($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame('{"value":"ok"}', $result->content[0]->text);
    }
}
