<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CapabilityManifestConsistencyTest extends TestCase
{
    private const PUBLIC_SUBSYSTEMS = [
        'database:read',
        'database:write',
        'database:schema',
        'cache:read',
        'cache:write',
        'file:read',
        'file:write',
        'mail:send',
        'backend:module',
        'backend:toolbar',
        'scheduler:task',
        'cli:command',
        'content:plugin',
        'content:contentElement',
        'typoscript:provider',
        'site:middleware',
        'auth:provider',
        'routing:enhancer',
        'tca:override',
        'xclass:override',
    ];

    #[Test]
    public function shippedManifestUsesThePublicSchemaForCoarseCapabilities(): void
    {
        $capabilities = $this->capabilities();
        $subsystems = $capabilities['subsystems'] ?? null;

        self::assertIsArray($subsystems);
        self::assertSame([], array_values(array_diff($subsystems, self::PUBLIC_SUBSYSTEMS)));
        self::assertArrayNotHasKey('tools', $capabilities, 'MCP tool policy belongs in capabilities.x-mcp.');
        self::assertArrayNotHasKey('requires', $capabilities, 'MCP dependency policy belongs in capabilities.x-mcp.');
        self::assertSame('*', $capabilities['database']['reads'] ?? null);
        self::assertSame('*', $capabilities['database']['writes'] ?? null);
        self::assertContains('cache:write', $subsystems);
        self::assertContains('scheduler:task', $subsystems);

        $paths = $capabilities['filesystem']['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertContains('./', $paths);

        $events = $capabilities['events'] ?? null;
        self::assertIsArray($events);
        self::assertNotEmpty($events['listens'] ?? []);
        self::assertNotEmpty($events['dispatches'] ?? []);

        $outbound = $capabilities['network']['outbound'] ?? null;
        self::assertIsArray($outbound);
        foreach ($outbound as $declaration) {
            self::assertIsArray($declaration);
            self::assertIsString($declaration['host'] ?? null);
            self::assertIsString($declaration['purpose'] ?? null);
            self::assertContains($declaration['protocol'] ?? null, ['https', 'http', 'smtp', 'ftp']);
        }
    }

    #[Test]
    public function everyToolAndPrerequisiteReferencesADeclaredSubsystem(): void
    {
        $capabilities = $this->capabilities();
        $mcp = $this->mcpExtension();
        $declared = array_fill_keys([
            ...($capabilities['subsystems'] ?? []),
            ...($mcp['runtime_subsystems'] ?? []),
        ], true);

        $requires = $mcp['requires'] ?? null;
        self::assertIsArray($requires);
        foreach ($requires as $subsystem => $dependencies) {
            self::assertIsString($subsystem);
            self::assertArrayHasKey($subsystem, $declared);
            self::assertIsArray($dependencies);
            foreach ($dependencies as $dependency) {
                self::assertIsString($dependency);
                self::assertArrayHasKey($dependency, $declared);
            }
        }

        foreach (['tools', 'external_tools'] as $inventory) {
            $definitions = $mcp[$inventory] ?? null;
            self::assertIsArray($definitions);
            foreach ($definitions as $tool => $requirements) {
                self::assertIsString($tool);
                self::assertIsArray($requirements);
                foreach ($requirements as $requirement) {
                    self::assertIsString($requirement);
                    self::assertArrayHasKey(
                        $requirement,
                        $declared,
                        sprintf('%s requires undeclared subsystem %s.', $tool, $requirement),
                    );
                }
            }
        }
    }

    #[Test]
    public function ownTableInventoryMatchesSqlSchema(): void
    {
        $declared = $this->capabilities()['database']['own_tables'] ?? null;
        self::assertIsArray($declared);

        $sql = file_get_contents($this->projectRoot() . '/ext_tables.sql');
        self::assertIsString($sql);
        preg_match_all('/CREATE\s+TABLE\s+([a-z0-9_]+)/i', $sql, $matches);
        $defined = $matches[1] ?? [];

        sort($declared);
        sort($defined);
        self::assertSame($defined, $declared);
    }

    #[Test]
    public function commandInventoryMatchesSymfonyServicesAndProjectsEveryTool(): void
    {
        $mcp = $this->mcpExtension();
        $commands = $mcp['commands'] ?? null;
        $tools = $mcp['tools'] ?? null;
        $externalTools = $mcp['external_tools'] ?? null;
        self::assertIsArray($commands);
        self::assertIsArray($tools);
        self::assertIsArray($externalTools);
        self::assertArrayHasKey('ability_system_site-info', $externalTools);
        self::assertSame([], array_intersect_key($tools, $externalTools), 'Native and external tool names must not collide.');

        $servicesFile = Yaml::parseFile($this->projectRoot() . '/Configuration/Services.yaml');
        $services = is_array($servicesFile['services'] ?? null) ? $servicesFile['services'] : [];
        $registeredCommands = [];
        foreach ($services as $definition) {
            if (!is_array($definition) || !is_array($definition['tags'] ?? null)) {
                continue;
            }
            foreach ($definition['tags'] as $tag) {
                if (is_array($tag)
                    && ($tag['name'] ?? null) === 'console.command'
                    && is_string($tag['command'] ?? null)
                    && str_starts_with($tag['command'], 'mcp:')
                ) {
                    $registeredCommands[] = $tag['command'];
                }
            }
        }

        $declaredCommands = array_keys($commands);
        sort($registeredCommands);
        sort($declaredCommands);
        self::assertSame($registeredCommands, $declaredCommands);

        $projectedTools = [];
        foreach ($commands as $command => $definition) {
            self::assertIsArray($definition, sprintf('Command "%s" needs a structured declaration.', $command));
            if (($definition['kind'] ?? null) !== 'tool') {
                continue;
            }
            self::assertIsString($definition['tool'] ?? null);
            self::assertArrayHasKey($definition['tool'], $tools, sprintf('Command "%s" references an unknown tool.', $command));
            $projectedTools[] = $definition['tool'];
        }

        $declaredTools = array_keys($tools);
        sort($projectedTools);
        sort($declaredTools);
        self::assertSame($declaredTools, $projectedTools, 'Every bundled MCP tool needs one ergonomic CLI projection.');
    }

    #[Test]
    public function skillInventoryMatchesBundledSkillDirectories(): void
    {
        $mcp = $this->mcpExtension();
        $skillDefinitions = $mcp['skills'] ?? null;
        $toolDefinitions = $mcp['tools'] ?? null;
        self::assertIsArray($skillDefinitions);
        self::assertIsArray($toolDefinitions);

        $declared = array_keys($skillDefinitions);
        $files = glob($this->projectRoot() . '/Resources/Private/Skills/*/SKILL.md') ?: [];
        $bundled = array_map(
            static fn(string $path): string => basename(dirname($path)),
            $files,
        );

        sort($declared);
        sort($bundled);
        self::assertSame($bundled, $declared);

        foreach ($skillDefinitions as $name => $definition) {
            self::assertIsString($name);
            self::assertIsArray($definition);

            $relativeSource = $definition['source'] ?? null;
            self::assertIsString($relativeSource);
            self::assertSame('Resources/Private/Skills/' . $name . '/SKILL.md', $relativeSource);
            $markdown = file_get_contents($this->projectRoot() . '/' . $relativeSource);
            self::assertIsString($markdown);

            self::assertMatchesRegularExpression('/\A---\R(.*?)\R---\R/s', $markdown);
            preg_match('/\A---\R(.*?)\R---\R/s', $markdown, $frontMatterMatch);
            $frontMatter = Yaml::parse($frontMatterMatch[1] ?? '');
            self::assertIsArray($frontMatter);
            self::assertSame($name, $frontMatter['name'] ?? null);
            self::assertSame(
                (bool)($frontMatter['user-invocable'] ?? false),
                (bool)($definition['user_invocable'] ?? false),
            );

            $declaredSkillTools = $definition['tools'] ?? null;
            self::assertIsArray($declaredSkillTools);
            foreach ($declaredSkillTools as $toolName) {
                self::assertIsString($toolName);
                self::assertArrayHasKey($toolName, $toolDefinitions);
            }

            $referencedTools = [];
            foreach (array_keys($toolDefinitions) as $toolName) {
                if (is_string($toolName) && preg_match('/\b' . preg_quote($toolName, '/') . '\b/', $markdown) === 1) {
                    $referencedTools[] = $toolName;
                }
            }
            sort($declaredSkillTools);
            sort($referencedTools);
            self::assertSame(
                $referencedTools,
                $declaredSkillTools,
                sprintf('Skill "%s" must declare every MCP tool referenced by its Markdown.', $name),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        $manifest = Yaml::parseFile($this->projectRoot() . '/Configuration/Capabilities.yaml');
        self::assertIsArray($manifest);
        $capabilities = $manifest['capabilities'] ?? null;
        self::assertIsArray($capabilities);
        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpExtension(): array
    {
        $mcp = $this->capabilities()['x-mcp'] ?? null;
        self::assertIsArray($mcp);
        return $mcp;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
