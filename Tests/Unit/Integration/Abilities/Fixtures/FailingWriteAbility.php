<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Destructive fixture ability that always throws, so the bridge's failure
 * mapping (AbilityResult::$ok false → CallToolResult::$isError true) is
 * exercised without any side effect.
 */
#[AsAbility(
    name: 'bridge-test/fail',
    title: 'Bridge failure',
    description: 'Always fails.',
    category: 'testing',
    scopes: ['testing:write'],
    riskTier: RiskTier::High,
    sideEffects: ['database:write'],
    idempotent: false,
    destructive: true,
)]
final class FailingWriteAbility extends AbstractAbility
{
    public function getInputSchema(): array
    {
        return [];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        throw new \RuntimeException('ability exploded', 1757700001);
    }
}
