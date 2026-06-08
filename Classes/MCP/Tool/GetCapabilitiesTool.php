<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\LocalModeService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
                . 'DDEV/local-mode is unlocking live writes / unrestricted file access. '
                . 'Pass "tool" to fetch a single tool\'s full, untrimmed schema/description — useful when the concise tools/list '
                . 'entry is not detailed enough (the server condenses tools/list by default to save context-window tokens).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tool' => [
                        'type' => 'string',
                        'description' => 'Optional. Name of a registered tool (e.g. "WriteTable"). Returns its full schema verbatim '
                            . 'instead of the capability manifest.',
                    ],
                ],
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
        $requestedTool = isset($params['tool']) && is_string($params['tool']) ? trim($params['tool']) : '';
        if ($requestedTool !== '') {
            return $this->describeTool($requestedTool);
        }

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

    /**
     * Return the full, untrimmed schema of a single tool. The ToolRegistry is
     * resolved lazily (not constructor-injected) to avoid a circular service
     * dependency: the registry instantiates every tool, including this one.
     */
    private function describeTool(string $toolName): CallToolResult
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool($toolName);
        if ($tool === null) {
            $available = array_keys($registry->getTools());
            sort($available);
            return $this->createErrorResult(
                'Unknown tool "' . $toolName . '". Available tools: ' . implode(', ', $available) . '.',
            );
        }

        $payload = [
            'tool' => $tool->getName(),
            'schema' => $tool->getSchema(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }
}
