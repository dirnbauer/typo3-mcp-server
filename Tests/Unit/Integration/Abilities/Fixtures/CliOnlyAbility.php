<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/** Fixture ability that is deliberately not exposed to the MCP surface. */
#[AsAbility(
    name: 'bridge-test/cli-only',
    title: 'Bridge CLI only',
    description: 'Never reaches the MCP catalog.',
    category: 'testing',
    scopes: [],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    expose: ['cli'],
)]
final class CliOnlyAbility extends AbstractAbility
{
    public function execute(array $input, ExecutionContext $context): mixed
    {
        return null;
    }
}
