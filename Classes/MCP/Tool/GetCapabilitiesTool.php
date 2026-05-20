<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\LocalModeService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Expose the active capability manifest + runtime mode to MCP clients.
 *
 * The first thing a well-behaved client should do at session start is call
 * this — it tells the LLM what the server can and cannot do, which tools are
 * gated, and whether DDEV/local mode is unlocking live writes. That's much
 * cheaper than attempting calls and hitting deny-list errors.
 *
 * No subsystem requirement: this tool is intentionally always callable.
 */
final class GetCapabilitiesTool extends AbstractTool
{
    public function __construct(
        private readonly CapabilityManifestService $manifest,
        private readonly LocalModeService $localMode,
        private readonly DevSiteToolService $devSiteTools,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Return the MCP server capability manifest (Configuration/Capabilities.yaml) plus runtime mode. '
                . 'Call this once at session start to learn which tools are available, which subsystems are declared, and whether '
                . 'DDEV/local-mode is unlocking live writes / unrestricted file access.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $manifest = $this->manifest->getManifest();
        $payload = [
            'manifest' => $manifest['capabilities'] ?? [],
            'enforced' => $this->manifest->isEnforced(),
            'localMode' => $this->localMode->describe(),
            'devSiteTools' => $this->devSiteTools->describe(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }
}
