<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\CreateSiteTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CreateSiteToolTest extends AbstractFunctionalTest
{
    private CreateSiteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(CreateSiteTool::class);
    }

    public function testToolRequiresAdminPrivileges(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'some-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => [],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', $this->getFirstTextContent($result));
    }

    public function testCreateDerivesDefaultFlagsFromIsoCodes(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'default-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame('default-flags-site', $data['identifier']);
        self::assertSame('us', $data['config']['languages'][0]['flag']);
        self::assertSame('de', $data['config']['languages'][1]['flag']);
    }

    public function testCreateAllowsExplicitFlagOverride(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'explicit-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'defaultLanguage' => [
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'iso-639-1' => 'en',
                'flag' => 'gb',
            ],
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                    'flag' => 'at',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('gb', $data['config']['languages'][0]['flag']);
        self::assertSame('at', $data['config']['languages'][1]['flag']);
    }

    public function testAddLanguagePreservesExistingSiteConfiguration(): void
    {
        $this->createExistingSiteConfiguration('existing-site', [
            [
                'title' => 'German',
                'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
        ]);
        $this->mergeSiteConfiguration('existing-site', [
            'websiteTitle' => 'Existing Site',
            'settings' => ['featureFlag' => true],
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
        ]);

        $result = $this->tool->execute([
            'action' => 'addLanguage',
            'identifier' => 'existing-site',
            'language' => [
                'title' => 'Chinese',
                'locale' => 'zh_CN.UTF-8',
                'iso-639-1' => 'zh',
                'base' => '/zh/',
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('languageAdded', $data['status']);
        self::assertSame(3, $data['totalLanguages']);

        $config = $this->readSiteConfiguration('existing-site');
        self::assertSame(1, $config['rootPageId']);
        self::assertSame('https://example.com/', $config['base']);
        self::assertSame('Existing Site', $config['websiteTitle']);
        self::assertTrue($config['settings']['featureFlag']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
        self::assertCount(3, $config['languages']);
        self::assertSame('zh', $config['languages'][2]['iso-639-1']);
        self::assertSame('/zh/', $config['languages'][2]['base']);
        self::assertSame('cn', $config['languages'][2]['flag']);
        self::assertSame(2, $config['languages'][2]['languageId']);
    }

    public function testReplaceLanguagesReplacesOnlyLanguageList(): void
    {
        $this->createExistingSiteConfiguration('replace-site', [
            [
                'title' => 'German',
                'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
            [
                'title' => 'French',
                'navigationTitle' => 'Français',
                'locale' => 'fr_FR.UTF-8',
                'iso-639-1' => 'fr',
                'base' => '/fr/',
            ],
        ]);
        $this->mergeSiteConfiguration('replace-site', [
            'websiteTitle' => 'Replace Site',
            'dependencies' => ['vendor/site-package'],
            'settings' => ['theme' => 'violet'],
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
            'languages' => [
                [
                    'title' => 'English',
                    'navigationTitle' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'flag' => 'us',
                    'hreflang' => 'en-us',
                ],
                [
                    'title' => 'German',
                    'navigationTitle' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'flag' => 'de',
                    'fallbackType' => 'strict',
                    'hreflang' => 'de-de',
                ],
                [
                    'title' => 'French',
                    'navigationTitle' => 'Français',
                    'enabled' => true,
                    'languageId' => 2,
                    'base' => '/fr/',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'flag' => 'fr',
                    'fallbackType' => 'fallback',
                ],
            ],
        ]);

        $result = $this->tool->execute([
            'action' => 'replaceLanguages',
            'identifier' => 'replace-site',
            'defaultLanguage' => [
                'title' => 'German',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
            ],
            'languages' => [
                [
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'base' => '/en/',
                ],
                [
                    'title' => 'Chinese',
                    'locale' => 'zh_CN.UTF-8',
                    'iso-639-1' => 'zh',
                    'base' => '/zh/',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('languagesReplaced', $data['status']);
        self::assertSame(3, $data['totalLanguages']);

        $config = $this->readSiteConfiguration('replace-site');
        self::assertSame(1, $config['rootPageId']);
        self::assertSame('https://example.com/', $config['base']);
        self::assertSame('Replace Site', $config['websiteTitle']);
        self::assertSame(['vendor/site-package'], $config['dependencies']);
        self::assertSame('violet', $config['settings']['theme']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
        self::assertCount(3, $config['languages']);

        self::assertSame('de', $config['languages'][0]['iso-639-1']);
        self::assertSame('/', $config['languages'][0]['base']);
        self::assertSame(0, $config['languages'][0]['languageId']);
        self::assertSame('Deutsch', $config['languages'][0]['navigationTitle']);
        self::assertSame('de-de', $config['languages'][0]['hreflang']);

        self::assertSame('en', $config['languages'][1]['iso-639-1']);
        self::assertSame('/en/', $config['languages'][1]['base']);
        self::assertSame(1, $config['languages'][1]['languageId']);
        self::assertSame('en-us', $config['languages'][1]['hreflang']);

        self::assertSame('zh', $config['languages'][2]['iso-639-1']);
        self::assertSame('/zh/', $config['languages'][2]['base']);
        self::assertSame(2, $config['languages'][2]['languageId']);
        self::assertSame('cn', $config['languages'][2]['flag']);
    }

    public function testCreateAcceptsDependenciesAndReturnsNoWarning(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'themed-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['vendor/site-package'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame(['vendor/site-package'], $data['config']['dependencies']);
        self::assertArrayNotHasKey('warning', $data);
    }

    public function testCreateMergesSetsAndDependencies(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'sets-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['vendor/a'],
            'sets' => ['vendor/b', 'vendor/a'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame(['vendor/a', 'vendor/b'], $data['config']['dependencies']);
    }

    public function testCreateWithoutRenderingEmitsWarning(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'no-render',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertArrayHasKey('warning', $data);
        self::assertStringContainsString('No site configuration or TypoScript template record found', $data['warning']);
    }

    public function testUpdateMergesDependenciesWhilePreservingOtherKeys(): void
    {
        $this->createExistingSiteConfiguration('update-site', [
            [
                'title' => 'German',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
        ]);
        $this->mergeSiteConfiguration('update-site', [
            'websiteTitle' => 'Existing Site',
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
        ]);

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'update-site',
            'dependencies' => ['vendor/theme'],
            'settings' => ['theme' => 'dark'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('updated', $data['status']);
        self::assertContains('dependencies', $data['appliedKeys']);
        self::assertContains('settings', $data['appliedKeys']);

        $config = $this->readSiteConfiguration('update-site');
        self::assertSame(['vendor/theme'], $config['dependencies']);
        self::assertSame('dark', $config['settings']['theme']);
        // Unrelated keys are preserved.
        self::assertSame('Existing Site', $config['websiteTitle']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
    }

    public function testUpdateRejectsEmptyRequest(): void
    {
        $this->createExistingSiteConfiguration('empty-update-site', []);

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'empty-update-site',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('dependencies, sets, settings, or config', $this->getFirstTextContent($result));
    }

    public function testUpdateAcceptsGenericConfigAndProtectsStructuralKeys(): void
    {
        $this->createExistingSiteConfiguration('protect-site', []);
        $before = $this->readSiteConfiguration('protect-site');

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'protect-site',
            'config' => [
                'errorHandling' => [['errorCode' => 404]],
                'rootPageId' => 9999,
                'base' => 'https://malicious.example/',
                'languages' => [],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('updated', $data['status']);
        self::assertContains('errorHandling', $data['appliedKeys']);

        $after = $this->readSiteConfiguration('protect-site');
        self::assertSame($before['rootPageId'], $after['rootPageId']);
        self::assertSame($before['base'], $after['base']);
        self::assertCount(count($before['languages']), $after['languages']);
        self::assertSame([['errorCode' => 404]], $after['errorHandling']);
    }

    /**
     * @param array<int, array<string, mixed>> $languages
     */
    private function createExistingSiteConfiguration(string $identifier, array $languages): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => $identifier,
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => $languages,
        ]);
        $this->assertSuccessfulToolResult($result);
        $this->flushSiteConfigurationCache();
    }

    /**
     * @param array<string, mixed> $siteConfiguration
     */
    private function mergeSiteConfiguration(string $identifier, array $siteConfiguration): void
    {
        $config = $this->readSiteConfiguration($identifier);
        $mergedConfiguration = array_replace_recursive($config, $siteConfiguration);
        $configPath = $this->getSiteConfigPath($identifier);
        GeneralUtility::writeFile($configPath . '/config.yaml', Yaml::dump($mergedConfiguration, 99, 2), true);
        $this->flushSiteConfigurationCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function readSiteConfiguration(string $identifier): array
    {
        $config = Yaml::parseFile($this->getSiteConfigPath($identifier) . '/config.yaml');
        self::assertIsArray($config);

        return $config;
    }

    private function getSiteConfigPath(string $identifier): string
    {
        /** @var SiteConfiguration $siteConfiguration */
        $siteConfiguration = $this->getService(SiteConfiguration::class);
        $paths = $siteConfiguration->getAllSiteConfigurationPaths();
        self::assertArrayHasKey($identifier, $paths);

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
                $cacheManager->getCache('core')->remove('sites-configuration');
            }
            if ($cacheManager->hasCache('runtime')) {
                $cacheManager->getCache('runtime')->remove('sites-configuration');
            }
        } catch (\Throwable) {
            // Ignore cache errors during functional tests.
        }
    }
}
