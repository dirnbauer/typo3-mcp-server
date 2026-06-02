<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Builds MCP client configuration snippets shown in the backend user module.
 */
final class McpClientConfigBuilder
{
    /**
     * @return array{command: string, args: list<string>, cwd?: string}
     */
    public function buildLocalStdioConfig(): array
    {
        $ddevProject = getenv('DDEV_PROJECT');
        if ($this->isTruthyEnvironmentValue(getenv('IS_DDEV_PROJECT')) && is_string($ddevProject) && $ddevProject !== '') {
            return [
                'command' => 'ddev',
                'args' => [
                    'exec',
                    '-p',
                    $ddevProject,
                    '--',
                    'php',
                    'vendor/bin/typo3',
                    'mcp:server',
                ],
            ];
        }

        return [
            'command' => 'php',
            'args' => [
                Environment::getProjectPath() . '/vendor/bin/typo3',
                'mcp:server',
            ],
            'cwd' => Environment::getProjectPath(),
        ];
    }

    public function getSiteName(): string
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $siteName = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['sitename'] ?? null)
            : null;

        return is_string($siteName) && $siteName !== '' ? $siteName : 'TYPO3 MCP Server';
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $serverConfig
     */
    public function buildMcpServersConfigJson(string $serverName, array $serverConfig): string
    {
        $json = json_encode(
            ['mcpServers' => [$serverName => $serverConfig]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        return is_string($json) ? $json : '{}';
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $serverConfig
     */
    public function buildCursorInstallUrl(string $serverName, array $serverConfig): string
    {
        $json = json_encode($serverConfig, JSON_UNESCAPED_SLASHES);
        $json = is_string($json) ? $json : '{}';
        $configParam = base64_encode($json);

        return 'cursor://anysphere.cursor-deeplink/mcp/install?name='
            . rawurlencode($serverName)
            . '&config='
            . $configParam;
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $serverConfig
     */
    public function buildCodexTomlConfig(string $serverName, array $serverConfig): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($serverName)) ?? 'typo3';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'typo3';
        }

        $lines = [
            '# Add to ~/.codex/config.toml',
            '[mcp_servers.' . $slug . ']',
            'command = "' . $this->escapeTomlString($serverConfig['command']) . '"',
        ];

        $args = array_map(fn(string $arg): string => '"' . $this->escapeTomlString($arg) . '"', $serverConfig['args']);
        $lines[] = 'args = [' . implode(', ', $args) . ']';

        if (isset($serverConfig['cwd']) && $serverConfig['cwd'] !== '') {
            $lines[] = 'cwd = "' . $this->escapeTomlString($serverConfig['cwd']) . '"';
        }

        return implode("\n", $lines) . "\n";
    }

    private function isTruthyEnvironmentValue(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strtolower($value) !== 'false'
            && $value !== '0';
    }

    private function escapeTomlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
