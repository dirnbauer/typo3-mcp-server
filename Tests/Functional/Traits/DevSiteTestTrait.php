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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
    }

    protected function disableDevSiteTools(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';
    }
}
