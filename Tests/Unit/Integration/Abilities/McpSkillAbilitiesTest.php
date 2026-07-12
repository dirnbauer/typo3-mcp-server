<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities;

use Hn\McpServer\Integration\Abilities\GetMcpSkillAbility;
use Hn\McpServer\Integration\Abilities\ListMcpSkillsAbility;
use Hn\McpServer\MCP\SkillRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\ExecutionContext;

final class McpSkillAbilitiesTest extends TestCase
{
    private SkillRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SkillRegistry(
            dirname(__DIR__, 4) . '/Resources/Private/Skills',
        );
    }

    #[Test]
    public function listAbilityProjectsEveryBundledSkill(): void
    {
        $result = (new ListMcpSkillsAbility($this->registry))->execute([], ExecutionContext::cli());

        self::assertIsArray($result);
        self::assertSame(2, $result['total'] ?? null);
        self::assertSame(
            ['typo3-content-edit', 'typo3-translate-page'],
            array_column($result['skills'] ?? [], 'name'),
        );
    }

    #[Test]
    public function getAbilityReturnsAgentSkillsMarkdownAndResourceUri(): void
    {
        $result = (new GetMcpSkillAbility($this->registry))->execute(
            ['name' => 'typo3-content-edit'],
            ExecutionContext::cli(),
        );

        self::assertIsArray($result);
        self::assertSame('typo3-content-edit', $result['name'] ?? null);
        self::assertSame('typo3-mcp:///skills/typo3-content-edit', $result['resourceUri'] ?? null);
        self::assertStringContainsString('# TYPO3 Content Editing Skill', (string)($result['markdown'] ?? ''));
    }

    #[Test]
    public function getAbilityRejectsUnknownSkill(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        (new GetMcpSkillAbility($this->registry))->execute(
            ['name' => 'missing-skill'],
            ExecutionContext::cli(),
        );
    }
}
