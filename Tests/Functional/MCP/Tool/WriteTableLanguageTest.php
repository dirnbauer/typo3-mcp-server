<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class WriteTableLanguageTest extends FunctionalTestCase
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
     * Test creating content with ISO language code
     */
    public function testCreateContentWithIsoLanguageCode(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Create content in German using ISO code
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Deutscher Titel',
                'bodytext' => 'Deutscher Inhalt',
                'sys_language_uid' => 'de',  // ISO code instead of numeric ID
                /**
                 * IMPORTANT: This test demonstrates a key feature of the WriteTableTool.
                 *
                 * Instead of using numeric language UIDs (which would require the LLM to know
                 * that German = 1, French = 2, etc.), the tool accepts ISO 639-1 language codes.
                 *
                 * The WriteTableTool automatically converts these ISO codes to the correct
                 * numeric UIDs based on the site configuration. This makes the API much more
                 * intuitive for LLMs and reduces the need for them to maintain mappings.
                 *
                 * Supported ISO codes are discovered from the site configuration and shown
                 * in the GetTableSchemaTool output for sys_language_uid fields.
                 */
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode((string)$result->content[0]->text, true);

        self::assertEquals('create', $data['action']);
        self::assertEquals('tt_content', $data['table']);
        self::assertIsInt($data['uid']);

        // Verify the created record has correct language UID
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $record = $connection->select(['*'], 'tt_content', ['uid' => $data['uid']])->fetchAssociative();

        self::assertNotFalse($record);
        self::assertEquals(1, $record['sys_language_uid']); // German has UID 1
        self::assertEquals('Deutscher Titel', $record['header']);
    }

    /**
     * Test error handling for invalid language code
     */
    public function testCreateWithInvalidLanguageCode(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test',
                'sys_language_uid' => 'xx',  // Invalid ISO code
            ],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Unknown language code: xx', $result->error ?? $result->content[0]->text);
    }

    /**
     * Test translate action
     */
    public function testTranslateRecord(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // First create a record in default language
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original English Content',
                'bodytext' => 'This is the original content',
            ],
        ]);

        self::assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $createData = json_decode((string)$createResult->content[0]->text, true);
        $originalUid = $createData['uid'];

        // Now translate it to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Deutscher Titel',
                'bodytext' => 'Das ist der uebersetzte Inhalt',
            ],
        ]);

        self::assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translateData = json_decode((string)$translateResult->content[0]->text, true);

        self::assertEquals('translate', $translateData['action']);
        self::assertEquals('tt_content', $translateData['table']);
        self::assertEquals($originalUid, $translateData['sourceUid']);
        self::assertEquals('de', $translateData['targetLanguage']);
        self::assertNotEmpty($translateData['translationUid']);

        // Check if translation UID was found
        if (!is_int($translateData['translationUid'])) {
            self::fail('Translation failed: ' . $translateData['translationUid']);
        }

        // Verify the translation was created - need to use BackendUtility to get workspace overlay
        $translation = BackendUtility::getRecord('tt_content', $translateData['translationUid']);

        self::assertNotFalse($translation, 'Translation record not found. UID was: ' . $translateData['translationUid']);
        self::assertIsArray($translation, 'Translation should be an array');

        self::assertEquals(1, $translation['sys_language_uid']); // German
        self::assertEquals($originalUid, $translation['l18n_parent']); // TYPO3 uses l18n_parent for tt_content
        self::assertEquals('Deutscher Titel', $translation['header']);
        self::assertEquals('Das ist der uebersetzte Inhalt', $translation['bodytext']);
    }

    /**
     * Test translate action applies provided page fields immediately
     */
    public function testTranslatePageAppliesProvidedTitle(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'pages',
            'uid' => 2,
            'data' => [
                'sys_language_uid' => 'de',
                'title' => 'Ueber uns',
                'nav_title' => 'Ueberblick',
            ],
        ]);

        self::assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translateData = json_decode((string)$translateResult->content[0]->text, true);
        self::assertIsInt($translateData['translationUid']);

        $translation = BackendUtility::getRecord('pages', $translateData['translationUid']);

        self::assertNotFalse($translation, 'Page translation record not found');
        self::assertIsArray($translation, 'Page translation should be an array');
        self::assertEquals(1, $translation['sys_language_uid']);
        self::assertEquals(2, $translation['l10n_parent']);
        self::assertEquals('Ueber uns', $translation['title']);
        self::assertEquals('Ueberblick', $translation['nav_title']);
    }

    /**
     * Translate without any translated field values must fail fast —
     * otherwise DataHandler's localize command just copies the source with
     * "[Translate to X:]" prefixes, which is not a real translation.
     */
    public function testTranslateRejectsMissingTranslatedContent(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original header',
                'bodytext' => 'Original body',
            ],
        ]);
        self::assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $createData = json_decode((string)$createResult->content[0]->text, true);
        $uid = (int)$createData['uid'];

        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'sys_language_uid' => 'de',
            ],
        ]);

        self::assertTrue($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $errorMessage = $translateResult->content[0]->text ?? '';
        self::assertStringContainsString('Translate requires translated field values', $errorMessage);
        self::assertStringContainsString('header', $errorMessage);
        self::assertStringContainsString('bodytext', $errorMessage);
    }

    /**
     * Test translate action with invalid language
     */
    public function testTranslateWithInvalidLanguage(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Create a record first
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test',
            ],
        ]);

        $createData = json_decode((string)$createResult->content[0]->text, true);
        $uid = $createData['uid'];

        // Try to translate to invalid language
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'sys_language_uid' => 'xx',
            ],
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        self::assertStringContainsString('Unknown language code: xx', $errorMessage);
    }

    /**
     * Test translating already translated record
     */
    public function testTranslateAlreadyTranslatedRecord(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Create original record
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ],
        ]);

        $createData = json_decode((string)$createResult->content[0]->text, true);
        $originalUid = $createData['uid'];

        // Translate to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Deutsch',
            ],
        ]);

        $translateData = json_decode((string)$translateResult->content[0]->text, true);
        $germanUid = $translateData['translationUid'];

        // Try to translate the German translation (should fail)
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $germanUid,
            'data' => [
                'sys_language_uid' => 'fr',
                'header' => 'Francais',
            ],
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        self::assertStringContainsString('Cannot translate a record that is already a translation', $errorMessage);
    }

    /**
     * Test duplicate translation prevention
     */
    public function testPreventDuplicateTranslation(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Create original record
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ],
        ]);

        $createData = json_decode((string)$createResult->content[0]->text, true);
        $originalUid = $createData['uid'];

        // Translate to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Deutsch',
            ],
        ]);

        self::assertFalse($translateResult->isError);

        // Try to translate to German again (should fail)
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Deutsch',
            ],
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        self::assertTrue(
            str_contains((string)$errorMessage, 'Translation already exists')
            || str_contains((string)$errorMessage, 'already are localizations')
            || str_contains((string)$errorMessage, 'already been localized'),
            'Expected error about existing translation, got: ' . $errorMessage,
        );
    }

    /**
     * Test updating translation with ISO code preservation
     */
    public function testUpdateTranslationMaintainsLanguage(): void
    {
        $tool = $this->getService(WriteTableTool::class);

        // Create original and translate
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ],
        ]);

        $createData = json_decode((string)$createResult->content[0]->text, true);
        $originalUid = $createData['uid'];

        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Deutsch',
            ],
        ]);

        $translateData = json_decode((string)$translateResult->content[0]->text, true);
        $germanUid = $translateData['translationUid'];

        // Update the German translation
        $updateResult = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $germanUid,
            'data' => [
                'header' => 'Aktualisierter deutscher Titel',
                'bodytext' => 'Aktualisierter deutscher Inhalt',
            ],
        ]);

        self::assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));

        // Verify the update - need to use BackendUtility to get workspace overlay
        $record = BackendUtility::getRecord('tt_content', $germanUid);

        self::assertNotFalse($record, 'German translation record not found');
        self::assertEquals('Aktualisierter deutscher Titel', $record['header']);
        self::assertEquals('Aktualisierter deutscher Inhalt', $record['bodytext']);
        self::assertEquals(1, $record['sys_language_uid']); // Still German
        self::assertEquals($originalUid, $record['l18n_parent']); // Still linked to original
    }
}
