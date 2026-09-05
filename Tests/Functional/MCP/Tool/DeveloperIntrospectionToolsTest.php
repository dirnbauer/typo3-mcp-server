<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\MCP\Tool\ApplicationInfoTool;
use Hn\McpServer\MCP\Tool\ContentBlocksTool;
use Hn\McpServer\MCP\Tool\LastErrorTool;
use Hn\McpServer\MCP\Tool\ListEventsTool;
use Hn\McpServer\MCP\Tool\MiddlewareStackTool;
use Hn\McpServer\MCP\Tool\PageTsConfigTool;
use Hn\McpServer\MCP\Tool\TypoScriptTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeveloperIntrospectionToolsTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
    }

    public function testApplicationInfoReportsLiveRuntimeWithoutLargePackageListByDefault(): void
    {
        $tool = $this->getService(ApplicationInfoTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([]));

        self::assertSame(14, $payload['typo3MajorVersion']);
        self::assertSame(PHP_VERSION, $payload['phpVersion']);
        self::assertIsArray($payload['database']);
        self::assertArrayHasKey('mcp_server', $payload['activeExtensions']);
        self::assertGreaterThan(0, $payload['composerPackageCount']);
        self::assertArrayNotHasKey('composerPackages', $payload);
        self::assertStringContainsString('packages', $payload['hint']);
    }

    public function testPageTsConfigReturnsOneResolvedBranchInsteadOfTheWholeTree(): void
    {
        $this->seedRootTsConfig('TCEFORM.tt_content.header.disabled = 1');
        $tool = $this->getService(PageTsConfigTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([
            'pageId' => 0,
            'path' => 'TCEFORM.tt_content.header.disabled',
        ]));

        self::assertSame(0, $payload['pageId']);
        self::assertSame('TCEFORM.tt_content.header.disabled', $payload['path']);
        self::assertSame('1', $payload['value']);
    }

    public function testTypoScriptReturnsACompiledSubtreeForTheRequestedPage(): void
    {
        $this->createTestSiteConfiguration();
        $this->connectionPool->getConnectionForTable('sys_template')->insert('sys_template', [
            'pid' => 1,
            'title' => 'Developer introspection test',
            'root' => 1,
            'clear' => 1,
            'config' => "page = PAGE\npage.10 = TEXT\npage.10.value = MCP compiled value",
        ]);
        $tool = $this->getService(TypoScriptTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([
            'pageId' => 1,
            'section' => 'setup',
            'path' => 'page.10.value',
        ]));

        self::assertSame(1, $payload['pageId']);
        self::assertSame('test-site', $payload['site']);
        self::assertSame('MCP compiled value', $payload['value']);
    }

    public function testMiddlewareStackReportsResolvedExecutionOrder(): void
    {
        $tool = $this->getService(MiddlewareStackTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([
            'stack' => 'frontend',
            'search' => 'typo3/cms-frontend',
        ]));

        self::assertSame('frontend', $payload['stack']);
        self::assertGreaterThan(0, $payload['matchCount']);
        self::assertIsInt($payload['middlewares'][0]['position']);
        self::assertStringContainsString('typo3/cms-frontend', $payload['middlewares'][0]['identifier']);
        self::assertArrayHasKey('class', $payload['middlewares'][0]);
    }

    public function testListEventsFindsRegisteredListenersForOneEvent(): void
    {
        $tool = $this->getService(ListEventsTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([
            'event' => 'BeforeRecordReadEvent',
            'withListenersOnly' => true,
        ]));

        self::assertSame(1, $payload['eventCount']);
        self::assertArrayHasKey(BeforeRecordReadEvent::class, $payload['events']);
        $event = $payload['events'][BeforeRecordReadEvent::class];
        self::assertGreaterThan(0, $event['listenerCount']);
        self::assertStringContainsString('SysFile', $event['listeners'][0]['service']);
    }

    public function testListEventsPaginatesBroadInventories(): void
    {
        $tool = $this->getService(ListEventsTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([
            'withListenersOnly' => true,
            'limit' => 2,
        ]));

        self::assertSame(2, $payload['eventCount']);
        self::assertGreaterThan(2, $payload['totalMatches']);
        self::assertTrue($payload['truncated']);
        self::assertSame(2, $payload['nextOffset']);
    }

    public function testContentBlocksExplainsWhenOptionalPackageIsUnavailable(): void
    {
        $tool = $this->getService(ContentBlocksTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([]));

        self::assertFalse($payload['available']);
        self::assertSame(0, $payload['contentBlockCount']);
        self::assertStringContainsString('content-blocks', $payload['hint']);
    }

    public function testLastErrorReturnsACompactStructuredFileLogEntry(): void
    {
        $logDirectory = Environment::getVarPath() . '/log';
        GeneralUtility::mkdir_deep($logDirectory);
        $context = json_encode([
            'exception_class' => \RuntimeException::class,
            'exception_code' => 777,
            'file' => '/var/www/html/public/index.php',
            'line' => 23,
            'message' => 'Developer tool test failure',
            'exception' => "RuntimeException: Developer tool test failure\n#0 /a.php(1): first()\n#1 /b.php(2): second()",
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        GeneralUtility::writeFile(
            $logDirectory . '/typo3_mcp-last-error.log',
            'Sat, 15 Aug 2026 10:00:00 +0000 [ERROR] request="abc" component="test": failed - ' . $context . "\n",
            true,
        );
        $tool = $this->getService(LastErrorTool::class);

        $payload = $this->extractJsonFromResult($tool->execute([]));

        self::assertSame(\RuntimeException::class, $payload['error']['exception']['class']);
        self::assertSame(777, $payload['error']['exception']['code']);
        self::assertCount(2, $payload['error']['trace']);
        self::assertArrayNotHasKey('message', $payload['error']);
    }

    private function seedRootTsConfig(string $tsConfig): void
    {
        $rootLine = [[
            'uid' => 1,
            'pid' => 0,
            'TSconfig' => $tsConfig,
            'tsconfig_includes' => '',
            'is_siteroot' => 0,
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
            't3ver_state' => 0,
            't3ver_stage' => 0,
            'doktype' => 0,
            'sorting' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]];

        $pageTsConfig = GeneralUtility::makeInstance(PageTsConfigFactory::class)
            ->create($rootLine, new NullSite(), null);
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        $hash = 'developer-introspection-' . md5($tsConfig);
        $cache->set('pageTsConfig-pid-to-hash-0', $hash);
        $cache->set('pageTsConfig-hash-to-object-' . $hash, $pageTsConfig);
    }

    private function createTestSiteConfiguration(): void
    {
        $siteDirectory = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDirectory);
        GeneralUtility::writeFile($siteDirectory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [[
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
            ]],
            'routes' => [],
            'errorHandling' => [],
        ], 99, 2), true);

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        foreach (['core', 'runtime'] as $cacheName) {
            if ($cacheManager->hasCache($cacheName)) {
                $cacheManager->getCache($cacheName)->remove('sites-configuration');
            }
        }
    }
}
