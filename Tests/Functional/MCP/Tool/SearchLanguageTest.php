<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SearchLanguageTest extends FunctionalTestCase
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

        // Import test data
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
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
     * Create test content with translations
     */
    protected function createTestContentWithTranslations(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');

        // Create German content translation
        $connection->insert('tt_content', [
            'uid' => 2000,
            'pid' => 2,
            'sys_language_uid' => 1,
            'l18n_parent' => 102,
            'CType' => 'text',
            'header' => 'Team Einführung',
            'bodytext' => 'Lernen Sie unser Team kennen',
            'colPos' => 0,
            'sorting' => 256,
        ]);

        // Create French content translation
        $connection->insert('tt_content', [
            'uid' => 2001,
            'pid' => 2,
            'sys_language_uid' => 2,
            'l18n_parent' => 102,
            'CType' => 'text',
            'header' => 'Introduction de l\'équipe',
            'bodytext' => 'Rencontrez notre équipe',
            'colPos' => 0,
            'sorting' => 256,
        ]);

        // Create content that exists only in German
        $connection->insert('tt_content', [
            'uid' => 2002,
            'pid' => 6,
            'sys_language_uid' => 1,
            'CType' => 'text',
            'header' => 'Kontaktformular',
            'bodytext' => 'Bitte füllen Sie das Formular aus',
            'colPos' => 0,
            'sorting' => 512,
        ]);

        // Create content with "all languages" flag
        $connection->insert('tt_content', [
            'uid' => 2003,
            'pid' => 1,
            'sys_language_uid' => -1,
            'CType' => 'text',
            'header' => 'Global Announcement',
            'bodytext' => 'This content appears in all languages',
            'colPos' => 0,
            'sorting' => 768,
        ]);
    }

    /**
     * Test that language parameter is shown in schema for multi-language sites
     */
    public function testLanguageParameterInSchema(): void
    {
        $tool = $this->getService(SearchTool::class);
        $schema = $tool->getSchema();

        // Should have language parameter with enum
        self::assertArrayHasKey('language', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('enum', $schema['inputSchema']['properties']['language']);
        self::assertContains('en', $schema['inputSchema']['properties']['language']['enum']);
        self::assertContains('de', $schema['inputSchema']['properties']['language']['enum']);
        self::assertContains('fr', $schema['inputSchema']['properties']['language']['enum']);
    }

    /**
     * Test searching without language filter (all languages)
     */
    public function testSearchAllLanguages(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);
        $result = $tool->execute([
            'terms' => ['team'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should find results in all languages
        self::assertStringContainsString('Team Introduction', $output); // English
        self::assertStringContainsString('Team Einführung', $output); // German
        self::assertStringContainsString('Team Members', $output); // English only
        // French translation doesn't contain "team" so won't be found

        // Should show language indicators for found content
        self::assertStringContainsString('🌐 Language: DE', $output);
        // French content won't be found because it doesn't contain "team"

        // Should not have language filter in output
        self::assertStringNotContainsString('Language Filter:', $output);
    }

    /**
     * Test searching with German language filter
     */
    public function testSearchGermanLanguage(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);
        $result = $tool->execute([
            'terms' => ['team'],
            'language' => 'de',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should show language filter
        self::assertStringContainsString('Language Filter: DE (ID: 1)', $output);

        // Should find German and default language content
        self::assertStringContainsString('Team Einführung', $output); // German translation
        self::assertStringContainsString('Team Introduction', $output); // Default fallback
        self::assertStringContainsString('Team Members', $output); // Default fallback

        // Should NOT find French content
        self::assertStringNotContainsString('Introduction de l\'équipe', $output);
    }

    /**
     * Test searching with French language filter
     */
    public function testSearchFrenchLanguage(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);
        // Search with French filter using French term
        $result = $tool->execute([
            'terms' => ['équipe'],
            'language' => 'fr',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should show language filter
        self::assertStringContainsString('Language Filter: FR (ID: 2)', $output);

        // Should find French content when searching for French term
        self::assertStringContainsString('Introduction de l\'équipe', $output); // French translation
        // Preview has highlighted terms with asterisks
        self::assertStringContainsString('Rencontrez notre', $output);
        self::assertStringContainsString('équipe', $output);

        // Should NOT find German or default content when searching French term
        self::assertStringNotContainsString('Team Einführung', $output);
        self::assertStringNotContainsString('Team Introduction', $output);
    }

    /**
     * Test searching with default language filter
     */
    public function testSearchDefaultLanguage(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);
        $result = $tool->execute([
            'terms' => ['team'],
            'language' => 'en',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should show language filter
        self::assertStringContainsString('Language Filter: EN (ID: 0)', $output);

        // Should find only default language content
        self::assertStringContainsString('Team Introduction', $output);
        self::assertStringContainsString('Team Members', $output);

        // Should NOT find translated content
        self::assertStringNotContainsString('Team Einführung', $output);
        self::assertStringNotContainsString('Introduction de l\'équipe', $output);
    }

    /**
     * Test searching for content that exists only in specific language
     */
    public function testSearchLanguageSpecificContent(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);

        // Search without language filter
        $result = $tool->execute([
            'terms' => ['Kontaktformular'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should find German-only content
        self::assertStringContainsString('Kontaktformular', $output);
        self::assertStringContainsString('🌐 Language: DE', $output);

        // Search with English filter
        $result = $tool->execute([
            'terms' => ['Kontaktformular'],
            'language' => 'en',
        ]);

        $output = $result->content[0]->text;

        // Should NOT find German-only content when filtering for English
        self::assertStringContainsString('No results found', $output);
    }

    /**
     * Test searching for content with "all languages" flag
     */
    public function testSearchAllLanguagesContent(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);

        // Test with each language filter - should always find "all languages" content
        foreach (['en', 'de', 'fr'] as $lang) {
            $result = $tool->execute([
                'terms' => ['Global Announcement'],
                'language' => $lang,
            ]);

            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $output = $result->content[0]->text;

            // Should find content marked for all languages
            self::assertStringContainsString('Global Announcement', $output);
            self::assertStringContainsString('🌐 Language: All', $output);
        }
    }

    /**
     * Test invalid language code
     */
    public function testInvalidLanguageCode(): void
    {
        $tool = $this->getService(SearchTool::class);
        $result = $tool->execute([
            'terms' => ['test'],
            'language' => 'xx',
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->error ?? $result->content[0]->text;
        self::assertStringContainsString('Unknown language code: xx', $errorMessage);
    }

    /**
     * Test AND logic with language filter
     */
    public function testAndLogicWithLanguage(): void
    {
        $this->createTestContentWithTranslations();

        $tool = $this->getService(SearchTool::class);
        $result = $tool->execute([
            'terms' => ['Team', 'kennen'],
            'termLogic' => 'AND',
            'language' => 'de',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        // Should find German content with both terms
        self::assertStringContainsString('Team Einführung', $output);
        // Preview shows highlighted terms with asterisks
        self::assertStringContainsString('Lernen Sie unser', $output);
        self::assertStringContainsString('kennen', $output);

        // Should not find English content (doesn't have "kennen")
        self::assertStringNotContainsString('Team Introduction', $output);
    }
}
