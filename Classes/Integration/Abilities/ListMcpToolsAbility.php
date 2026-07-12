<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

#[AsAbility(
    name: 'typo3-mcp/list-tools',
    title: 'List TYPO3 MCP tools',
    description: 'Lists the effective workspace-safe MCP tool catalog and JSON Schemas.',
    category: 'mcp',
    scopes: ['mcp:tools:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    expose: ['cli', 'rest'],
)]
final class ListMcpToolsAbility extends AbstractMcpCatalogAbility
{
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['tools', 'total'],
            'properties' => [
                'tools' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $tools = $this->catalog->list();
        return ['tools' => $tools, 'total' => count($tools)];
    }
}
