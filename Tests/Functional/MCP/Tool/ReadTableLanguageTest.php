<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReadTableLanguageTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();

        // Import test data with translations
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/tt_content_translations.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user
        $this->setUpBackendUser(1);
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
     * Test reading records with language filter
     */
    public function testReadRecordsWithLanguageFilter(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Read German content
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'language' => 'de',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        self::assertEquals('tt_content', $data['table']);
        self::assertGreaterThan(0, \count($data['records']));

        // All records should be in German (sys_language_uid = 1)
        foreach ($data['records'] as $record) {
            self::assertEquals(1, $record['sys_language_uid']);
        }
    }

    /**
     * Test reading with invalid language code
     */
    public function testReadWithInvalidLanguageCode(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'language' => 'xx', // Invalid language code
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Unknown language code: xx', $result->error ?? $result->content[0]->text ?? '');
    }

    /**
     * Test reading default language content
     */
    public function testReadDefaultLanguageContent(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Read English content (default language)
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'language' => 'en',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        // All records should be in English (sys_language_uid = 0)
        foreach ($data['records'] as $record) {
            self::assertEquals(0, $record['sys_language_uid']);
        }
    }

    /**
     * Test reading translations with source information
     */
    public function testReadTranslationsWithSourceInfo(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Read German content with translation source info
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'language' => 'de',
            'includeTranslationSource' => true,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        // Should have translation source data
        self::assertArrayHasKey('translationSource', $data);
        self::assertIsArray($data['translationSource']);

        // Check translation metadata for at least one record
        $foundTranslation = false;
        foreach ($data['records'] as $record) {
            if (!empty($record['l18n_parent'])) {
                $foundTranslation = true;
                $uid = $record['uid'];
                self::assertArrayHasKey($uid, $data['translationSource']);

                $sourceInfo = $data['translationSource'][$uid];
                self::assertArrayHasKey('sourceUid', $sourceInfo);
                self::assertArrayHasKey('sourceLanguage', $sourceInfo);
                self::assertArrayHasKey('inheritedFields', $sourceInfo);

                self::assertEquals($record['l18n_parent'], $sourceInfo['sourceUid']);
                self::assertEquals('en', $sourceInfo['sourceLanguage']);
            }
        }
        self::assertTrue($foundTranslation, 'No translated records found in test data');
    }

    /**
     * Test that translation source is not included for default language
     */
    public function testNoTranslationSourceForDefaultLanguage(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Read default language with translation source flag
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'language' => 'en',
            'includeTranslationSource' => true,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        // Should not have translation source for default language
        self::assertArrayNotHasKey('translationSource', $data);
    }

    /**
     * Test reading all languages (no filter)
     */
    public function testReadAllLanguages(): void
    {
        $tool = $this->getService(ReadTableTool::class);

        // Read without language filter
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        // Should contain records from multiple languages
        $languageUids = array_unique(array_column($data['records'], 'sys_language_uid'));
        self::assertCount(3, $languageUids); // 0, 1, 2
        self::assertContains(0, $languageUids);
        self::assertContains(1, $languageUids);
        self::assertContains(2, $languageUids);
    }
}
