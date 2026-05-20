<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\Command\InstallEditorSkillsCommand;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class DevSiteToolRegistryTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    public function testDevSiteToolsAreHiddenWhenLocalModeIsOff(): void
    {
        $this->disableDevSiteTools();
        $registry = $this->getService(ToolRegistry::class);
        $names = array_map(static fn($tool) => $tool->getName(), $registry->getTools());

        self::assertNotContains('SiteSettings', $names);
        self::assertNotContains('ListViewHelpers', $names);
        self::assertContains('ReadTable', $names);
    }

    public function testDevSiteToolsAreListedWhenLocalModeIsOn(): void
    {
        $this->enableDevSiteTools();
        $registry = $this->getService(ToolRegistry::class);
        $names = array_map(static fn($tool) => $tool->getName(), $registry->getTools());

        self::assertContains('SiteSettings', $names);
        self::assertContains('ListViewHelpers', $names);
    }

    public function testStrictSandboxHidesDevSiteToolsEvenWhenLocalUnsafeModeIsOn(): void
    {
        $this->enableDevSiteTools();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox'] = true;

        $registry = $this->getService(ToolRegistry::class);
        $names = array_map(static fn($tool) => $tool->getName(), $registry->getTools());

        self::assertNotContains('SiteSettings', $names);
        self::assertNotContains('ListViewHelpers', $names);
    }

    public function testInstallEditorSkillsCommandCopiesSkills(): void
    {
        $previousCwd = getcwd();
        $tempDir = sys_get_temp_dir() . '/mcp-skills-test-' . bin2hex(random_bytes(4));
        mkdir($tempDir);
        chdir($tempDir);

        try {
            $command = $this->getService(InstallEditorSkillsCommand::class);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([]);

            self::assertSame(0, $exitCode);
            self::assertDirectoryExists($tempDir . '/.claude/skills/typo3-content-edit');
            self::assertDirectoryExists($tempDir . '/.claude/skills/typo3-translate-page');
            self::assertFileExists($tempDir . '/.claude/skills/typo3-content-edit/SKILL.md');
        } finally {
            chdir($previousCwd !== false ? $previousCwd : $tempDir);
            $this->removeDirectory($tempDir);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }
}
