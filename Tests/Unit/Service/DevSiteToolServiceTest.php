<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\LocalModeService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[DevSiteOnly]
final class DevSiteOnlyTestDummy {}

final class DevSiteToolServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);
        putenv('IS_DDEV_PROJECT');
        putenv('DDEV_PROJECT');
        putenv('DDEV_HOSTNAME');
        putenv('DDEV_TLD');
        parent::tearDown();
    }

    public function testDetectsDevSiteOnlyAttribute(): void
    {
        self::assertTrue(DevSiteToolService::hasDevSiteOnlyAttribute(new DevSiteOnlyTestDummy()));
    }

    public function testIsUnavailableWhenLocalModeIsOff(): void
    {
        putenv('IS_DDEV_PROJECT=true');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = ['localUnsafeMode' => 'off'];

        $service = new DevSiteToolService(new LocalModeService(new ExtensionConfiguration()));

        self::assertFalse($service->isAvailable());
    }

    public function testIsAvailableWhenLocalModeIsOn(): void
    {
        putenv('IS_DDEV_PROJECT');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = ['localUnsafeMode' => 'on'];
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);

        $service = new DevSiteToolService(new LocalModeService(new ExtensionConfiguration()));

        self::assertTrue($service->isAvailable());
    }
}
