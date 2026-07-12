<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

#[AsAbility(
    name: 'typo3-mcp/describe-tool',
    title: 'Describe a TYPO3 MCP tool',
    description: 'Returns one effective MCP tool contract including its JSON Schema.',
    category: 'mcp',
    scopes: ['mcp:tools:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    expose: ['cli', 'rest'],
)]
final class DescribeMcpToolAbility extends AbstractMcpCatalogAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1]],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'schema'],
            'properties' => [
                'name' => ['type' => 'string'],
                'schema' => ['type' => 'object'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Tool name must be a non-empty string.');
        }
        return $this->catalog->describe($name);
    }
}
