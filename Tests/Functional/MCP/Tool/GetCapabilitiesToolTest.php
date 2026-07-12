<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetCapabilitiesTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

final class GetCapabilitiesToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function returnsTheShippedManifestAndRuntimeMode(): void
    {
        $tool = $this->get(GetCapabilitiesTool::class);

        $result = $tool->execute([]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Manifest must round-trip the shipped Configuration/Capabilities.yaml.
        self::assertArrayHasKey('manifest', $payload);
        self::assertSame('mcp_server', $payload['manifest']['extension'] ?? null);
        self::assertContains('database:read', $payload['manifest']['subsystems'] ?? []);
        self::assertContains('file:write', $payload['manifest']['subsystems'] ?? []);

        // Fine-grained MCP inventory is namespaced away from the public TYPO3
        // capability schema, while remaining fully introspectable by clients.
        $mcp = $payload['manifest']['x-mcp'] ?? null;
        self::assertIsArray($mcp);
        self::assertArrayHasKey('ReadTable', $mcp['tools'] ?? []);
        self::assertSame(['database:read'], $mcp['tools']['ReadTable']);
        self::assertSame(
            ['cli:safe', 'database:read', 'database:write', 'scheduler:task', 'network:scheduler'],
            $mcp['tools']['SolrIndexQueue'],
        );
        self::assertSame('ReadTable', $mcp['commands']['mcp:read-table']['tool'] ?? null);
        self::assertArrayHasKey('typo3-content-edit', $mcp['skills'] ?? []);

        // Network outbound default is closed (`self`) and uses the structured
        // capability-manifest representation.
        self::assertSame('self', $payload['manifest']['network']['outbound'][0]['host'] ?? null);

        // Runtime mode is reported.
        self::assertArrayHasKey('localMode', $payload);
        self::assertArrayHasKey('enabled', $payload['localMode']);
        self::assertArrayHasKey('enforced', $payload);
        self::assertTrue($payload['enforced']);
    }
}
