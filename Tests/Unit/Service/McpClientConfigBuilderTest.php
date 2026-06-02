<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\McpClientConfigBuilder;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\Environment;

final class McpClientConfigBuilderTest extends TestCase
{
    public function testLocalStdioConfigUsesDdevProjectWhenAvailable(): void
    {
        $this->withEnvironment([
            'IS_DDEV_PROJECT' => 'true',
            'DDEV_PROJECT' => 'example-project',
        ], function (): void {
            $config = (new McpClientConfigBuilder())->buildLocalStdioConfig();

            self::assertSame('ddev', $config['command']);
            self::assertSame([
                'exec',
                '-p',
                'example-project',
                '--',
                'php',
                'vendor/bin/typo3',
                'mcp:server',
            ], $config['args']);
            self::assertArrayNotHasKey('cwd', $config);
        });
    }

    public function testLocalStdioConfigUsesProjectBinaryAndWorkingDirectoryOutsideDdev(): void
    {
        $this->withEnvironment([
            'IS_DDEV_PROJECT' => false,
            'DDEV_PROJECT' => false,
            'DDEV_HOSTNAME' => false,
            'DDEV_TLD' => false,
        ], function (): void {
            $config = (new McpClientConfigBuilder())->buildLocalStdioConfig();

            self::assertSame('php', $config['command']);
            self::assertSame([
                Environment::getProjectPath() . '/vendor/bin/typo3',
                'mcp:server',
            ], $config['args']);
            self::assertSame(Environment::getProjectPath(), $config['cwd']);
        });
    }

    public function testCodexTomlConfigContainsCommandAndArgs(): void
    {
        $builder = new McpClientConfigBuilder();
        $config = [
            'command' => 'php',
            'args' => [Environment::getProjectPath() . '/vendor/bin/typo3', 'mcp:server'],
            'cwd' => Environment::getProjectPath(),
        ];

        $toml = $builder->buildCodexTomlConfig('My Site', $config);

        self::assertStringContainsString('[mcp_servers.my_site]', $toml);
        self::assertStringContainsString('command = "php"', $toml);
        self::assertStringContainsString('mcp:server', $toml);
    }

    public function testCursorInstallUrlEncodesLocalStdioConfig(): void
    {
        $builder = new McpClientConfigBuilder();
        $config = [
            'command' => 'php',
            'args' => [Environment::getProjectPath() . '/vendor/bin/typo3', 'mcp:server'],
            'cwd' => Environment::getProjectPath(),
        ];

        $url = $builder->buildCursorInstallUrl('Example Site', $config);
        $query = [];
        parse_str((string)parse_url((string)$url, PHP_URL_QUERY), $query);

        self::assertSame('Example Site', $query['name']);
        self::assertSame($config, json_decode((string)base64_decode((string)$query['config'], true), true));
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function withEnvironment(array $environment, \Closure $callback): void
    {
        $previous = [];
        foreach ($environment as $key => $_value) {
            $current = getenv($key);
            $previous[$key] = $current === false ? false : $current;
        }

        try {
            foreach ($environment as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
