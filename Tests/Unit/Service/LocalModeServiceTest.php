<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\LocalModeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class LocalModeServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        // Don't bleed env into other tests
        putenv('IS_DDEV_PROJECT');
        putenv('DDEV_PROJECT');
        putenv('DDEV_HOSTNAME');
        putenv('DDEV_TLD');
        parent::tearDown();
    }

    #[Test]
    public function offSettingDisablesLocalModeEvenInDdev(): void
    {
        putenv('IS_DDEV_PROJECT=true');
        $service = $this->createSubject(['localUnsafeMode' => 'off']);

        self::assertFalse($service->isLocalMode());
        self::assertFalse($service->allowsLiveWrites());
        self::assertFalse($service->allowsUnrestrictedFileAccess());
    }

    #[Test]
    public function onSettingEnablesLocalModeOutsideDdev(): void
    {
        putenv('IS_DDEV_PROJECT');
        $service = $this->createSubject(['localUnsafeMode' => 'on']);

        self::assertTrue($service->isLocalMode());
        self::assertTrue($service->allowsLiveWrites());
        self::assertTrue($service->allowsUnrestrictedFileAccess());
    }

    #[Test]
    public function autoEnablesLocalModeWhenDdevEnvIsPresent(): void
    {
        putenv('IS_DDEV_PROJECT=true');
        $service = $this->createSubject(['localUnsafeMode' => 'auto']);

        self::assertTrue($service->isLocalMode());
    }

    #[Test]
    public function describeReturnsAllFlags(): void
    {
        putenv('IS_DDEV_PROJECT=true');
        $service = $this->createSubject(['localUnsafeMode' => 'auto']);

        $info = $service->describe();
        self::assertSame('auto', $info['setting']);
        self::assertTrue($info['ddev']);
        self::assertTrue($info['enabled']);
        self::assertArrayHasKey('development_context', $info);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config): LocalModeService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $config;
        return new LocalModeService(new ExtensionConfiguration());
    }
}
