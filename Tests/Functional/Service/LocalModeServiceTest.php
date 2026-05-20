<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\LocalModeService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LocalModeServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);

        parent::tearDown();
    }

    public function testUserTsconfigCanEnableLocalUnsafeMode(): void
    {
        $this->setUserTsConfig('options.mcpServer.localUnsafeMode = on');

        $service = $this->getLocalModeService();

        self::assertTrue($service->isLocalMode());
        self::assertTrue($service->allowsLiveWrites());
        self::assertTrue($service->allowsUnrestrictedFileAccess());
        self::assertTrue($service->allowsDevTools());
    }

    public function testUserTsconfigStrictSandboxOverridesUnsafeExtensionSetting(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $this->setUserTsConfig('options.mcpServer.strictSandbox = 1');

        $service = $this->getLocalModeService();

        self::assertFalse($service->isLocalMode());
        self::assertFalse($service->allowsLiveWrites());
        self::assertFalse($service->allowsUnrestrictedFileAccess());
        self::assertFalse($service->allowsDevTools());
    }

    public function testCoreFeatureFlagStrictSandboxOverridesUnsafeExtensionSetting(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox'] = true;

        $service = $this->getLocalModeService();

        self::assertFalse($service->isLocalMode());
        self::assertFalse($service->allowsLiveWrites());
        self::assertFalse($service->allowsUnrestrictedFileAccess());
        self::assertFalse($service->allowsDevTools());
    }

    private function setUserTsConfig(string $tsConfig): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->update('be_users', ['TSconfig' => $tsConfig], ['uid' => 1]);

        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
    }

    private function getLocalModeService(): LocalModeService
    {
        return new LocalModeService(GeneralUtility::makeInstance(ExtensionConfiguration::class));
    }
}
