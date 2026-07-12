<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

#[AsAbility(
    name: 'typo3-mcp/execute-tool',
    title: 'Execute a TYPO3 MCP tool',
    description: 'Executes a named MCP tool through the same workspace, permission, schema, and capability gates.',
    category: 'mcp',
    scopes: ['mcp:tools:execute'],
    riskTier: RiskTier::Critical,
    sideEffects: ['database:read', 'database:write', 'file:read', 'file:write', 'network:outbound'],
    idempotent: false,
    destructive: true,
    // Keep arbitrary tool arguments off REST until the upstream Abilities
    // trace store supports field-level redaction. Native MCP and trusted CLI
    // remain the authoritative execution surfaces.
    expose: ['cli'],
)]
final class ExecuteMcpToolAbility extends AbstractMcpCatalogAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'arguments' => ['type' => 'object'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['content'],
            'properties' => [
                'content' => ['type' => 'array'],
                'isError' => ['type' => 'boolean'],
                'structuredContent' => [],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Tool name must be a non-empty string.');
        }
        $arguments = [];
        $rawArguments = $input['arguments'] ?? [];
        if (is_array($rawArguments)) {
            foreach ($rawArguments as $key => $value) {
                if (is_string($key)) {
                    $arguments[$key] = $value;
                }
            }
        }
        return $this->catalog->execute($name, $arguments)->jsonSerialize();
    }
}
