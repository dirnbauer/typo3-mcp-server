<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\MCP\SkillRegistry;
use Hn\McpServer\Service\AbilityBackendUserContextService;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

#[AsAbility(
    name: 'typo3-mcp/list-skills',
    title: 'List TYPO3 MCP skills',
    description: 'Lists the bundled editor workflows projected as MCP prompts and resources.',
    category: 'mcp',
    scopes: ['mcp:skills:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    expose: ['cli', 'rest'],
)]
final class ListMcpSkillsAbility extends AbstractMcpAbility
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        ?AbilityBackendUserContextService $backendUserContext = null,
    ) {
        parent::__construct($backendUserContext);
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['skills', 'total'],
            'properties' => [
                'skills' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $skills = [];
        foreach ($this->skillRegistry->getSkills() as $skill) {
            $skills[] = [
                'name' => $skill->name,
                'description' => $skill->description,
                'userInvocable' => $skill->userInvocable,
                'prompt' => $skill->userInvocable ? $skill->name : null,
                'resourceUri' => 'typo3-mcp:///skills/' . $skill->name,
            ];
        }

        return ['skills' => $skills, 'total' => count($skills)];
    }
}
