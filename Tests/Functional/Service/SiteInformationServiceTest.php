<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\SiteInformationService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteInformationServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private SiteInformationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->createRelativeBaseSiteConfiguration();

        $service = $this->getContainer()->get(SiteInformationService::class);
        assert($service instanceof SiteInformationService);
        $this->service = $service;
    }

    public function testGetAllDomainsFallsBackToCurrentRequestHost(): void
    {
        $requestFactory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $requestFactory->createServerRequest('GET', 'https://editor.example.test/mcp')
            ->withHeader('Host', 'editor.example.test');
        $this->service->setCurrentRequest($request);

        self::assertSame(['editor.example.test'], $this->service->getAllDomains());
        self::assertSame(
            'Available domain: editor.example.test',
            $this->service->getAvailableDomainsText(),
        );
    }

    public function testGeneratePageUrlUsesCurrentRequestHostAndScheme(): void
    {
        $requestFactory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $requestFactory->createServerRequest('GET', 'http://editor.example.test/mcp')
            ->withHeader('Host', 'editor.example.test');
        $this->service->setCurrentRequest($request);

        self::assertSame(
            'http://editor.example.test/contact',
            $this->service->generatePageUrl(6),
        );
    }

    private function createRelativeBaseSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => '/',
            'websiteTitle' => 'Request Fallback Test Site',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'hreflang' => 'en-us',
                    'direction' => 'ltr',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        $configPath = $this->instancePath . '/typo3conf/sites/request-fallback-site';
        GeneralUtility::mkdir_deep($configPath);

        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);

        $this->flushSiteConfigurationCache();
    }

    private function flushSiteConfigurationCache(): void
    {
        $cacheFile = $this->instancePath
            . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        if (!class_exists(CacheManager::class)) {
            return;
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
