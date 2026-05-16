<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\SiteSetTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SiteSetToolTest extends AbstractFunctionalTest
{
    private SiteSetTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(SiteSetTool::class);
    }

    public function testFindReturnsKnownCoreSiteSet(): void
    {
        $result = $this->tool->execute([
            'action' => 'find',
            'query' => 'email',
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('found', $data['status']);
        self::assertGreaterThanOrEqual(1, $data['total']);
        self::assertContains('typo3/email', array_column($data['siteSets'], 'name'));
    }

    public function testAddAndRemoveSiteSetPreservesExistingDependencies(): void
    {
        $this->createExistingSiteConfiguration('site-set-site', ['existing/package']);

        $addResult = $this->tool->execute([
            'action' => 'add',
            'identifier' => 'site-set-site',
            'siteSet' => 'typo3/email',
        ]);

        $addData = $this->extractJsonFromResult($addResult);
        self::assertSame('added', $addData['status']);
        self::assertSame(['existing/package', 'typo3/email'], $addData['dependencies']);
        self::assertSame(['existing/package', 'typo3/email'], $this->readSiteConfiguration('site-set-site')['dependencies']);

        $addAgainResult = $this->tool->execute([
            'action' => 'add',
            'identifier' => 'site-set-site',
            'siteSet' => 'typo3/email',
        ]);

        $addAgainData = $this->extractJsonFromResult($addAgainResult);
        self::assertSame('unchanged', $addAgainData['status']);
        self::assertSame(['existing/package', 'typo3/email'], $addAgainData['dependencies']);

        $removeResult = $this->tool->execute([
            'action' => 'remove',
            'identifier' => 'site-set-site',
            'siteSet' => 'typo3/email',
        ]);

        $removeData = $this->extractJsonFromResult($removeResult);
        self::assertSame('removed', $removeData['status']);
        self::assertSame(['existing/package'], $removeData['dependencies']);
        self::assertSame(['existing/package'], $this->readSiteConfiguration('site-set-site')['dependencies']);
    }

    public function testAddRejectsUnknownSiteSetWithoutChangingConfig(): void
    {
        $this->createExistingSiteConfiguration('unknown-site-set-site', ['existing/package']);

        $result = $this->tool->execute([
            'action' => 'add',
            'identifier' => 'unknown-site-set-site',
            'siteSet' => 'unknown/package',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Unknown site set "unknown/package"', $this->getFirstTextContent($result));
        self::assertSame(['existing/package'], $this->readSiteConfiguration('unknown-site-set-site')['dependencies']);
    }

    /**
     * @param list<string> $dependencies
     */
    private function createExistingSiteConfiguration(string $identifier, array $dependencies = []): void
    {
        $siteWriter = $this->getService(SiteWriter::class);
        $siteWriter->write($identifier, [
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => $dependencies,
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
