<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;
use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\LocalModeService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ToolRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        parent::tearDown();
    }

    public function testRegistryWrapsDirectToolInterfaceImplementation(): void
    {
        $tool = new class implements ToolInterface {
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

        $registeredTool = $registry->getTool('NativeTool');
        self::assertInstanceOf(CompatibleToolAdapter::class, $registeredTool);
        self::assertNotSame($tool, $registeredTool);
    }

    public function testRegistryKeepsAbstractToolsUntouched(): void
    {
        $tool = new class extends AbstractTool {
            public function getName(): string
            {
                return 'NativeAbstractTool';
            }

            public function getSchema(): array
            {
                return [
                    'description' => 'Native abstract tool',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ];
            }

            protected function doExecute(array $params): CallToolResult
            {
                return new CallToolResult([], false);
            }
        };

        $registry = new ToolRegistry([$tool]);

        self::assertSame($tool, $registry->getTool('NativeAbstractTool'));
    }

    public function testRegistryAdaptsLegacyTaggedToolWithoutGetSchema(): void
    {
        // Adapter mechanics are independent of the production allowlist.
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['enforceCapabilityManifest' => '0']);
        GeneralUtility::addInstance(
            CapabilityManifestService::class,
            new CapabilityManifestService($configuration, self::createStub(SiteFinder::class)),
        );
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

    public function testRegistryRejectsDuplicateToolNames(): void
    {
        $tool = new class implements ToolInterface {
            public function getName(): string
            {
                return 'Duplicate';
            }
            public function getSchema(): array
            {
                return ['inputSchema' => ['type' => 'object']];
            }
            public function execute(array $params): CallToolResult
            {
                return new CallToolResult([]);
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate MCP tool name: Duplicate');
        new ToolRegistry([$tool, $tool]);
    }

    public function testAttributesAreReadFromTheToolClass(): void
    {
        $tool = new #[AdminOnly] #[DevSiteOnly] class extends AbstractTool {
            public function getSchema(): array
            {
                return ['inputSchema' => ['type' => 'object']];
            }

            protected function doExecute(array $params): CallToolResult
            {
                return new CallToolResult([]);
            }
        };

        self::assertTrue($tool->isAdminOnly());
        self::assertTrue($tool->isDevSiteOnly());
    }

    public function testAttributesOfAdaptedToolsAreReadFromTheWrappedClass(): void
    {
        $legacyTool = new #[DevSiteOnly] class {
            public function getName(): string
            {
                return 'LegacyDevTool';
            }

            public function execute(): string
            {
                return 'ok';
            }
        };

        $registry = new ToolRegistry([$legacyTool]);
        $tool = $registry->getTool('LegacyDevTool');

        self::assertNotNull($tool);
        self::assertTrue($tool->isDevSiteOnly());
        self::assertFalse($tool->isAdminOnly());
    }

    public function testDevSiteOnlyToolsAreHiddenOutsideLocalMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = ['localUnsafeMode' => 'off'];
        $devTool = new #[DevSiteOnly] class extends AbstractTool {
            public function getName(): string
            {
                return 'DevOnly';
            }

            public function getSchema(): array
            {
                return ['inputSchema' => ['type' => 'object']];
            }

            protected function doExecute(array $params): CallToolResult
            {
                return new CallToolResult([]);
            }
        };
        $plainTool = new class extends AbstractTool {
            public function getName(): string
            {
                return 'Plain';
            }

            public function getSchema(): array
            {
                return ['inputSchema' => ['type' => 'object']];
            }

            protected function doExecute(array $params): CallToolResult
            {
                return new CallToolResult([]);
            }
        };

        try {
            $registry = new ToolRegistry(
                [$devTool, $plainTool],
                null,
                new DevSiteToolService(new LocalModeService(new ExtensionConfiguration())),
            );

            self::assertSame(['Plain'], array_keys($registry->getTools()));
            self::assertSame($devTool, $registry->getTool('DevOnly'), 'Lookup by name stays possible; execute() enforces the gate.');
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        }
    }
}
