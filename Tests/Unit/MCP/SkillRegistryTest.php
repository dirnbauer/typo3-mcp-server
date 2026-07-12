<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/mcp-skill-test-' . bin2hex(random_bytes(6));
        mkdir($this->path . '/valid-skill', 0777, true);
        file_put_contents($this->path . '/valid-skill/SKILL.md', <<<'MD'
---
name: valid-skill
description: A validated workflow
user-invocable: true
---

# Valid Skill
MD);
    }

    public function testLoadsAndValidatesSkillMetadata(): void
    {
        $skill = (new SkillRegistry($this->path))->getSkill('valid-skill');

        self::assertNotNull($skill);
        self::assertSame('A validated workflow', $skill->description);
        self::assertTrue($skill->userInvocable);
        self::assertStringContainsString('# Valid Skill', $skill->markdown);
    }

    public function testRejectsTraversalAndInvalidNames(): void
    {
        $registry = new SkillRegistry($this->path);
        self::assertNull($registry->getSkill('../valid-skill'));
        self::assertNull($registry->getSkill('Valid_Skill'));
    }

    protected function tearDown(): void
    {
        @unlink($this->path . '/valid-skill/SKILL.md');
        @rmdir($this->path . '/valid-skill');
        @rmdir($this->path);
    }
}
