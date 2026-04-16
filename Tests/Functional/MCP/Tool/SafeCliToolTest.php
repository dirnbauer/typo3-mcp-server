<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SafeCliTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class SafeCliToolTest extends AbstractFunctionalTest
{
    private SafeCliTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(SafeCliTool::class);
    }

    public function testDisallowedCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => 'database:drop']);
        self::assertTrue($result->isError, 'Expected error for disallowed command');
    }

    public function testDisallowedArgumentReturnsError(): void
    {
        $result = $this->tool->execute([
            'command' => 'cache:flush',
            'arguments' => ['--some-evil-arg'],
        ]);
        self::assertTrue($result->isError, 'Expected error for disallowed argument');
    }

    public function testShellInjectionArgumentRejected(): void
    {
        $result = $this->tool->execute([
            'command' => 'cache:flush',
            'arguments' => ['; rm -rf /'],
        ]);
        self::assertTrue($result->isError, 'Expected error for shell injection attempt');
    }

    public function testPipeInjectionRejected(): void
    {
        $result = $this->tool->execute([
            'command' => 'extension:list',
            'arguments' => ['| cat /etc/passwd'],
        ]);
        self::assertTrue($result->isError, 'Expected error for pipe injection attempt');
    }

    public function testSchemaContainsAllowedCommands(): void
    {
        $schema = $this->tool->getSchema();
        self::assertArrayHasKey('inputSchema', $schema);

        $properties = $schema['inputSchema']['properties'] ?? [];
        self::assertArrayHasKey('command', $properties);
        self::assertArrayHasKey('enum', $properties['command']);

        $allowedCommands = $properties['command']['enum'];
        self::assertContains('cache:flush', $allowedCommands);
        self::assertContains('extension:list', $allowedCommands);
        self::assertContains('site:list', $allowedCommands);
        self::assertNotContains('database:drop', $allowedCommands);
    }

    public function testEmptyCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => '']);
        self::assertTrue($result->isError, 'Expected error for empty command');
    }
}
