<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\PromptRegistry;
use Hn\McpServer\MCP\SkillRegistry;
use Mcp\Server\McpServerException;
use Mcp\Types\TextContent;
use PHPUnit\Framework\TestCase;

final class PromptRegistryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/mcp-prompt-test-' . bin2hex(random_bytes(6));
        mkdir($this->path . '/edit-content', 0777, true);
        file_put_contents($this->path . '/edit-content/SKILL.md', <<<'MD'
---
name: edit-content
description: Edit content safely
user-invocable: true
---

# Workflow
MD);
    }

    public function testProjectsInvocableSkillsAsPrompts(): void
    {
        $registry = new PromptRegistry(new SkillRegistry($this->path));

        $list = $registry->listPrompts();
        self::assertCount(1, $list->prompts);
        self::assertSame('edit-content', $list->prompts[0]->name);

        $result = $registry->getPrompt('edit-content', ['request' => 'Change page 42', 'context' => 'German']);
        self::assertInstanceOf(TextContent::class, $result->messages[0]->content);
        self::assertStringContainsString('Change page 42', $result->messages[0]->content->text);
        self::assertStringContainsString('German', $result->messages[0]->content->text);
    }

    public function testUnknownPromptIsInvalidParams(): void
    {
        $registry = new PromptRegistry(new SkillRegistry($this->path));
        try {
            $registry->getPrompt('missing');
            self::fail('Unknown prompts must fail.');
        } catch (McpServerException $exception) {
            self::assertSame(-32602, $exception->error->code);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path . '/edit-content/SKILL.md');
        @rmdir($this->path . '/edit-content');
        @rmdir($this->path);
    }
}
