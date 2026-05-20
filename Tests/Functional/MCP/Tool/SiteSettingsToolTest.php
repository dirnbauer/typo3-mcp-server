<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\SiteSettingsTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteSettingsToolTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    private SiteSettingsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
        $this->tool = $this->getService(SiteSettingsTool::class);
    }

    public function testToolIsBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();

        $result = $this->tool->execute([
            'action' => 'listDefinitions',
            'identifier' => 'dev-site-settings',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('local development mode', $this->getFirstTextContent($result));
    }

    public function testListDefinitionsFromEmailSiteSet(): void
    {
        $this->createSiteWithEmailSet('dev-site-settings');

        $result = $this->tool->execute([
            'action' => 'listDefinitions',
            'identifier' => 'dev-site-settings',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        self::assertSame('ok', $data['status']);
        self::assertGreaterThan(0, $data['total']);
        self::assertContains('email.format', array_column($data['definitions'], 'key'));
    }

    public function testUpdateValidatesEnumAndPersistsSettings(): void
    {
        $this->createSiteWithEmailSet('dev-site-settings-update');

        $invalid = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'dev-site-settings-update',
            'settings' => ['unknown.setting.key' => 'value'],
        ]);
        self::assertTrue($invalid->isError);

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'dev-site-settings-update',
            'settings' => ['email.format' => 'html'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        self::assertSame('updated', $data['status']);
        self::assertSame('html', $data['settings']['email.format']);

        $settingsFile = $this->getSiteConfigPath('dev-site-settings-update') . '/settings.yaml';
        self::assertFileExists($settingsFile);
        $yaml = Yaml::parseFile($settingsFile);
        self::assertIsArray($yaml);
        self::assertSame('html', $yaml['email.format']);
    }

    private function createSiteWithEmailSet(string $identifier): void
    {
        $siteWriter = $this->getService(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['typo3/email'],
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                    'hreflang' => 'en-us',
                ],
            ],
        ]);
        $this->flushSiteConfigurationCache();
    }

    private function getSiteConfigPath(string $identifier): string
    {
        /** @var SiteConfiguration $siteConfiguration */
        $siteConfiguration = $this->getService(SiteConfiguration::class);
        $paths = $siteConfiguration->getAllSiteConfigurationPaths();

        return $paths[$identifier];
    }

    private function flushSiteConfigurationCache(): void
    {
        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            if ($cacheManager->hasCache('core')) {
                $cacheManager->getCache('core')->flush();
            }
        } catch (\Throwable) {
        }
    }
}
