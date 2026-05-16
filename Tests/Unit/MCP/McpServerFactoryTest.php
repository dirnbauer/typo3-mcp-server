<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

final class McpServerFactoryTest extends TestCase
{
    private function createFactory(): McpServerFactory
    {
        $registry = new ToolRegistry([]);
        return new McpServerFactory($registry);
    }

    public function testGetServerNameReturnsSiteNameFromGlobals(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['sitename' => 'My Test Site']];

        self::assertSame('My Test Site', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenNoSiteName(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['sitename' => '']];

        self::assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenNoConfig(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        self::assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenSysIsNotArray(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => 'invalid'];

        self::assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    public function testToolsCallReturnsErrorResultForUnknownTool(): void
    {
        $factory = $this->createFactory();
        $server = $factory->createServer();
        $handlers = $server->getHandlers();
        self::assertIsCallable($handlers['tools/call']);

        $params = new CallToolRequestParams('NonExistentTool', []);
        $result = $handlers['tools/call']($params);

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue((bool)$result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringContainsString('NonExistentTool', $result->content[0]->text);
        self::assertStringContainsString('tools/list', $result->content[0]->text);
    }

    public function testLegacyTaggedToolsAppearInToolsListAndCanBeCalled(): void
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
                $value = $params['value'] ?? '';
                return is_string($value) ? $value : '';
            }
        };

        $factory = new McpServerFactory(new ToolRegistry([$legacyTool]));
        $server = $factory->createServer();
        $handlers = $server->getHandlers();

        self::assertIsCallable($handlers['tools/list']);
        self::assertIsCallable($handlers['tools/call']);

        $listResult = $handlers['tools/list']();
        self::assertIsArray($listResult['tools']);
        self::assertIsArray($listResult['tools'][0]);
        self::assertSame('LegacyTool', $listResult['tools'][0]['name']);
        self::assertSame('Legacy tool description', $listResult['tools'][0]['description']);
        self::assertArrayHasKey('inputSchema', $listResult['tools'][0]);

        $callResult = $handlers['tools/call'](new CallToolRequestParams('LegacyTool', ['value' => 'ok']));
        self::assertInstanceOf(CallToolResult::class, $callResult);
        self::assertFalse((bool)$callResult->isError);
        self::assertInstanceOf(TextContent::class, $callResult->content[0]);
        self::assertSame('ok', $callResult->content[0]->text);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }
}
