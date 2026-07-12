<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Mcp\Server\McpServerException;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class McpServerFactoryTest extends TestCase
{
    private function createFactory(?ToolRegistry $registry = null): McpServerFactory
    {
        $registry ??= new ToolRegistry([]);

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

    public function testToolsCallReturnsInvalidParamsForUnknownTool(): void
    {
        $factory = $this->createFactory();
        $server = $factory->createServer();
        $handlers = $server->getHandlers();
        self::assertIsCallable($handlers['tools/call']);

        $params = new CallToolRequestParams('NonExistentTool', []);
        try {
            $handlers['tools/call']($params);
            self::fail('Unknown tools must be returned as JSON-RPC Invalid Params errors.');
        } catch (McpServerException $exception) {
            self::assertSame(-32602, $exception->error->code);
            self::assertStringContainsString('NonExistentTool', $exception->error->message);
        }
    }

    public function testToolsCallNormalizesOmittedArgumentsToEmptyArray(): void
    {
        $this->allowExternalToolExecutionForAdapterTest();
        $tool = new class {
            public function getName(): string
            {
                return 'ParameterlessTool';
            }

            /** @return array<string, mixed> */
            public function getSchema(): array
            {
                return [
                    'description' => 'Parameterless tool',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ];
            }

            /** @param array<string, mixed> $params */
            public function execute(array $params): CallToolResult
            {
                return new CallToolResult([new TextContent(json_encode($params, JSON_THROW_ON_ERROR))], false);
            }
        };

        $handlers = $this->createFactory(new ToolRegistry([$tool]))->createServer()->getHandlers();
        $result = $handlers['tools/call'](new CallToolRequestParams('ParameterlessTool', null));

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse((bool)$result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame('[]', $result->content[0]->text);
    }

    public function testLegacyTaggedToolsAppearInToolsListAndCanBeCalled(): void
    {
        // This test isolates the legacy adapter contract. Production external
        // tools need an explicit capabilities.x-mcp.external_tools entry.
        $this->allowExternalToolExecutionForAdapterTest();
        $legacyTool = new class {
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

        $factory = $this->createFactory(new ToolRegistry([$legacyTool]));
        $server = $factory->createServer();
        $handlers = $server->getHandlers();

        self::assertIsCallable($handlers['tools/list']);
        self::assertIsCallable($handlers['tools/call']);

        $listResult = $handlers['tools/list']();
        self::assertInstanceOf(ListToolsResult::class, $listResult);
        self::assertCount(1, $listResult->tools);
        self::assertSame('LegacyTool', $listResult->tools[0]->name);
        self::assertSame('Legacy tool description', $listResult->tools[0]->description);

        $callResult = $handlers['tools/call'](new CallToolRequestParams('LegacyTool', ['value' => 'ok']));
        self::assertInstanceOf(CallToolResult::class, $callResult);
        self::assertFalse((bool)$callResult->isError);
        self::assertInstanceOf(TextContent::class, $callResult->content[0]);
        self::assertSame('ok', $callResult->content[0]->text);
    }

    public function testJsonTextResultAlsoUsesStructuredContent(): void
    {
        // See testLegacyTaggedToolsAppearInToolsListAndCanBeCalled().
        $this->allowExternalToolExecutionForAdapterTest();
        $legacyTool = new class {
            public function getName(): string
            {
                return 'JsonTool';
            }
            public function getDescription(): string
            {
                return 'Returns JSON';
            }
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
            public function execute(array $params): string
            {
                return '{"ok":true,"count":2}';
            }
        };

        $handlers = $this->createFactory(new ToolRegistry([$legacyTool]))->createServer()->getHandlers();
        $result = $handlers['tools/call'](new CallToolRequestParams('JsonTool', []));

        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertSame(['ok' => true, 'count' => 2], $result->structuredContent);
        self::assertSame('{"ok":true,"count":2}', $result->content[0]->text);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    private function allowExternalToolExecutionForAdapterTest(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['enforceCapabilityManifest' => '0']);
        $siteFinder = self::createStub(SiteFinder::class);
        GeneralUtility::addInstance(
            CapabilityManifestService::class,
            new CapabilityManifestService($configuration, $siteFinder),
        );
    }
}
