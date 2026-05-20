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
    public function testDetectsDevSiteOnlyAttribute(): void
    {
        self::assertTrue(DevSiteToolService::hasDevSiteOnlyAttribute(new DevSiteOnlyTestDummy()));
    }

    public function testIsUnavailableWhenLocalModeIsOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';
        putenv('IS_DDEV_PROJECT');

        $service = new DevSiteToolService(new LocalModeService(
            $this->createMock(ExtensionConfiguration::class),
        ));

        self::assertFalse($service->isAvailable());
    }

    public function testIsAvailableWhenLocalModeIsOn(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mcp_server')->willReturn(['localUnsafeMode' => 'on']);
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);

        $service = new DevSiteToolService(new LocalModeService($extensionConfiguration));

        self::assertTrue($service->isAvailable());
    }
}
