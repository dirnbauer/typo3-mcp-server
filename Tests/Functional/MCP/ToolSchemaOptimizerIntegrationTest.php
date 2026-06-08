<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

/**
 * Verifies that the MCP `tools/list` payload is condensed by default to save
 * context-window tokens, that `schemaDetail = full` restores verbatim text,
 * and that the full schema stays retrievable via the GetCapabilities tool.
 */
final class ToolSchemaOptimizerIntegrationTest extends AbstractFunctionalTest
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function listTools(): array
    {
        $factory = $this->getService(McpServerFactory::class);
        $server = $factory->createServer();
        $handlers = $server->getHandlers();
        self::assertIsCallable($handlers['tools/list']);
        $result = $handlers['tools/list']();

        $byName = [];
        foreach ($result['tools'] as $tool) {
            $byName[$tool['name']] = $tool;
        }
        return $byName;
    }

    public function testToolsListIsCondensedByDefault(): void
    {
        // No schemaDetail set => concise default.
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['schemaDetail']);

        $tools = $this->listTools();
        self::assertArrayHasKey('WriteTable', $tools);
        $description = $tools['WriteTable']['description'];

        // Critical gotchas survive the condensing of the top-level description.
        self::assertStringContainsString('REQUIRED', $description);
        self::assertStringContainsString('MUST', $description);
        // ... but the trailing walkthrough prose is dropped (ellipsis marker).
        self::assertStringEndsWith('…', rtrim($description));
        self::assertStringNotContainsString('Before creating content, use GetPage', $description);

        // Per-field descriptions are condensed too, while keeping their gotcha.
        $dataField = $tools['WriteTable']['inputSchema']['properties']['data']['description'];
        self::assertStringContainsString('REPLACES', $dataField);
        self::assertStringEndsWith('…', rtrim($dataField));
    }

    public function testFullModeKeepsVerbatimDescriptions(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['schemaDetail'] = 'full';

        $tools = $this->listTools();
        $description = $tools['WriteTable']['description'];

        // Full mode keeps the closing sentence that concise mode trims, and the
        // per-field walkthroughs are kept verbatim too.
        self::assertStringContainsString('Before creating content, use GetPage', $description);
        $dataField = $tools['WriteTable']['inputSchema']['properties']['data']['description'];
        self::assertStringContainsString('SEARCH-AND-REPLACE', $dataField);
    }

    public function testGetCapabilitiesReturnsFullSchemaOnDemand(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['schemaDetail']);

        $tools = $this->listTools();
        $conciseLength = strlen($tools['WriteTable']['description']);

        $tool = $this->getService(\Hn\McpServer\MCP\Tool\GetCapabilitiesTool::class);
        $result = $tool->execute(['tool' => 'WriteTable']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        self::assertSame('WriteTable', $payload['tool']);
        $fullDescription = $payload['schema']['description'];

        // GetCapabilities ignores the concise setting and returns verbatim text.
        self::assertStringContainsString('Before creating content, use GetPage', $fullDescription);
        self::assertGreaterThan($conciseLength, strlen($fullDescription));
    }
}
