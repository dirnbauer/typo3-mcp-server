<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Read-only fixture ability that records the ExecutionContext the bridge
 * handed to the abilities executor.
 */
#[AsAbility(
    name: 'bridge-test/echo',
    title: 'Bridge echo',
    description: 'Returns the given message.',
    category: 'testing',
    scopes: ['testing:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    instructions: 'Pass any message.',
)]
final class ContextRecordingAbility extends AbstractAbility
{
    public ?ExecutionContext $lastContext = null;

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['message'],
            'additionalProperties' => false,
            'properties' => [
                'message' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['echo'],
            'properties' => ['echo' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $this->lastContext = $context;
        $message = $input['message'] ?? '';

        return ['echo' => is_string($message) ? $message : ''];
    }
}
