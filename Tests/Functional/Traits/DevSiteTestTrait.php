<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

/**
 * Helpers for dev-site MCP tool tests.
 */
trait DevSiteTestTrait
{
    protected function enableDevSiteTools(): void
    {
        $this->setLocalUnsafeMode('on');
    }

    protected function disableDevSiteTools(): void
    {
        $this->setLocalUnsafeMode('off');
    }

    private function setLocalUnsafeMode(string $mode): void
    {
        $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $extensions = is_array($confVars['EXTENSIONS'] ?? null) ? $confVars['EXTENSIONS'] : [];
        $mcpServer = is_array($extensions['mcp_server'] ?? null) ? $extensions['mcp_server'] : [];

        $mcpServer['localUnsafeMode'] = $mode;
        $extensions['mcp_server'] = $mcpServer;
        $confVars['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }
}
