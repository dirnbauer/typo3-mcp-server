<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\MCP\SkillRegistry;
use Hn\McpServer\Service\AbilityBackendUserContextService;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

#[AsAbility(
    name: 'typo3-mcp/get-skill',
    title: 'Get a TYPO3 MCP skill',
    description: 'Returns one bundled editor workflow in Agent Skills Markdown format.',
    category: 'mcp',
    scopes: ['mcp:skills:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    expose: ['cli', 'rest'],
)]
final class GetMcpSkillAbility extends AbstractMcpAbility
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        ?AbilityBackendUserContextService $backendUserContext = null,
    ) {
        parent::__construct($backendUserContext);
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9][a-z0-9-]*$',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'description', 'userInvocable', 'markdown', 'resourceUri'],
            'properties' => [
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'userInvocable' => ['type' => 'boolean'],
                'markdown' => ['type' => 'string'],
                'resourceUri' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Skill name must be a non-empty string.');
        }

        $skill = $this->skillRegistry->getSkill($name);
        if ($skill === null) {
            throw new \OutOfBoundsException(sprintf('Unknown bundled skill "%s".', $name));
        }

        return [
            'name' => $skill->name,
            'description' => $skill->description,
            'userInvocable' => $skill->userInvocable,
            'markdown' => $skill->markdown,
            'resourceUri' => 'typo3-mcp:///skills/' . $skill->name,
        ];
    }
}
