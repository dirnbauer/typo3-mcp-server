<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class LanguageServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected LanguageService $languageService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();

        // Initialize the language service
        $service = $this->getContainer()->get(LanguageService::class);
        \assert($service instanceof LanguageService);
        $this->languageService = $service;
    }

    /**
     * Create a site configuration with multiple languages
     */
    protected function createMultiLanguageSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
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
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0',
                ],
                2 => [
                    'title' => 'French',
                    'enabled' => true,
                    'languageId' => 2,
                    'base' => '/fr/',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'hreflang' => 'fr-fr',
                    'direction' => 'ltr',
                    'flag' => 'fr',
                    'navigationTitle' => 'Français',
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0,1',
                ],
                3 => [
                    'title' => 'Spanish',
                    'enabled' => true,
                    'languageId' => 3,
                    'base' => '/es/',
                    'locale' => 'es_ES.UTF-8',
                    'iso-639-1' => 'es',
                    'hreflang' => 'es-es',
                    'direction' => 'ltr',
                    'flag' => 'es',
                    'navigationTitle' => 'Español',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        // Write the site configuration
        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);

        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);
    }

    /**
     * Test getting UID from ISO code
     */
    public function testGetUidFromIsoCode(): void
    {
        // Test valid ISO codes
        self::assertEquals(0, $this->languageService->getUidFromIsoCode('en'));
        self::assertEquals(1, $this->languageService->getUidFromIsoCode('de'));
        self::assertEquals(2, $this->languageService->getUidFromIsoCode('fr'));
        self::assertEquals(3, $this->languageService->getUidFromIsoCode('es'));

        // Test case insensitivity
        self::assertEquals(1, $this->languageService->getUidFromIsoCode('DE'));
        self::assertEquals(2, $this->languageService->getUidFromIsoCode('Fr'));

        // Test invalid ISO code
        self::assertNull($this->languageService->getUidFromIsoCode('xx'));
    }

    /**
     * Test getting ISO code from UID
     */
    public function testGetIsoCodeFromUid(): void
    {
        self::assertEquals('en', $this->languageService->getIsoCodeFromUid(0));
        self::assertEquals('de', $this->languageService->getIsoCodeFromUid(1));
        self::assertEquals('fr', $this->languageService->getIsoCodeFromUid(2));
        self::assertEquals('es', $this->languageService->getIsoCodeFromUid(3));

        // Test invalid UID
        self::assertNull($this->languageService->getIsoCodeFromUid(99));
    }

    /**
     * Test getting available ISO codes
     */
    public function testGetAvailableIsoCodes(): void
    {
        $isoCodes = $this->languageService->getAvailableIsoCodes();

        self::assertIsArray($isoCodes);
        self::assertCount(4, $isoCodes);
        self::assertContains('en', $isoCodes);
        self::assertContains('de', $isoCodes);
        self::assertContains('fr', $isoCodes);
        self::assertContains('es', $isoCodes);
    }

    /**
     * Test getting default language ISO code
     */
    public function testGetDefaultIsoCode(): void
    {
        $defaultIsoCode = $this->languageService->getDefaultIsoCode();

        self::assertEquals('en', $defaultIsoCode);
    }

    /**
     * Test checking if ISO code is available
     */
    public function testIsIsoCodeAvailable(): void
    {
        self::assertTrue($this->languageService->isIsoCodeAvailable('en'));
        self::assertTrue($this->languageService->isIsoCodeAvailable('de'));
        self::assertTrue($this->languageService->isIsoCodeAvailable('DE')); // Case insensitive

        self::assertFalse($this->languageService->isIsoCodeAvailable('xx'));
        self::assertFalse($this->languageService->isIsoCodeAvailable('jp'));
    }

    /**
     * Test getting all language mappings
     */
    public function testGetAllMappings(): void
    {
        $mappings = $this->languageService->getAllMappings();

        self::assertIsArray($mappings);
        self::assertCount(4, $mappings);

        self::assertEquals(0, $mappings['en']);
        self::assertEquals(1, $mappings['de']);
        self::assertEquals(2, $mappings['fr']);
        self::assertEquals(3, $mappings['es']);
    }

    /**
     * Test getting all language information
     */
    public function testGetAllLanguageInfo(): void
    {
        $languages = $this->languageService->getAllLanguageInfo();

        self::assertIsArray($languages);
        self::assertCount(4, $languages);

        // Check first language (English)
        $english = $languages[0];
        self::assertEquals(0, $english['uid']);
        self::assertEquals('en', $english['isoCode']);
        self::assertEquals('English', $english['title']);
        // TYPO3 Locale object toString returns format like 'en-US' not 'en_US.UTF-8'
        self::assertEquals('en-US', $english['locale']);
        self::assertTrue($english['enabled']);

        // Check sorting by UID
        self::assertEquals(1, $languages[1]['uid']);
        self::assertEquals(2, $languages[2]['uid']);
        self::assertEquals(3, $languages[3]['uid']);
    }

    /**
     * Test ISO code extraction from different locale formats
     */
    public function testIsoCodeExtractionFromVariousFormats(): void
    {
        // Create a site with various locale formats
        $siteConfiguration = [
            'rootPageId' => 2,
            'base' => 'https://example2.com/',
            'websiteTitle' => 'Test Site 2',
            'languages' => [
                10 => [
                    'title' => 'Italian',
                    'enabled' => true,
                    'languageId' => 10,
                    'base' => '/it/',
                    'locale' => 'it_IT', // Without .UTF-8
                    'iso-639-1' => 'it',
                    'hreflang' => 'it-it',
                ],
                11 => [
                    'title' => 'Japanese',
                    'enabled' => true,
                    'languageId' => 11,
                    'base' => '/ja/',
                    'locale' => 'ja_JP.UTF-8',
                    'iso-639-1' => '', // Empty ISO code
                    'hreflang' => 'ja', // Single language code
                ],
                12 => [
                    'title' => 'Chinese',
                    'enabled' => true,
                    'languageId' => 12,
                    'base' => '/zh/',
                    'locale' => 'zh_CN.UTF-8',
                    // No iso-639-1 field
                    'hreflang' => 'zh-cn',
                ],
            ],
        ];

        // Write the additional site configuration
        $configPath = $this->instancePath . '/typo3conf/sites/test-site-2';
        GeneralUtility::mkdir_deep($configPath);

        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);

        $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $newLanguageService = new LanguageService($siteFinder);

        // Test that all formats are properly extracted
        self::assertEquals(10, $newLanguageService->getUidFromIsoCode('it'));
        self::assertEquals(11, $newLanguageService->getUidFromIsoCode('ja'));
        self::assertEquals(12, $newLanguageService->getUidFromIsoCode('zh'));
    }
}
