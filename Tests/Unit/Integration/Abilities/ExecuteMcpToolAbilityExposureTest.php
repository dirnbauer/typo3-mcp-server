<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities;

use Hn\McpServer\Integration\Abilities\ExecuteMcpToolAbility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;

final class ExecuteMcpToolAbilityExposureTest extends TestCase
{
    #[Test]
    public function genericExecutionIsCliOnlySoRestTracesCannotPersistToolArguments(): void
    {
        $attributes = (new \ReflectionClass(ExecuteMcpToolAbility::class))
            ->getAttributes(AsAbility::class);

        self::assertCount(1, $attributes);
        $definition = $attributes[0]->newInstance();
        self::assertSame([ExecutionContext::SURFACE_CLI], $definition->expose);
        self::assertNotContains(ExecutionContext::SURFACE_REST, $definition->expose);
    }
}
