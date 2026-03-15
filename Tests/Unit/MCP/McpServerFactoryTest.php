<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\MCP\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class McpServerFactoryTest extends TestCase
{
    private function createFactory(): McpServerFactory
    {
        $registry = new ToolRegistry([]);
        return new McpServerFactory($registry);
    }

    public function testGetServerNameReturnsSiteNameFromGlobals(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'My Test Site';

        $this->assertSame('My Test Site', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenNoSiteName(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = '';

        $this->assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenNoConfig(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        $this->assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    public function testGetServerNameReturnsFallbackWhenSysIsNotArray(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => 'invalid'];

        $this->assertSame('TYPO3 MCP Server', $this->createFactory()->getServerName());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }
}
