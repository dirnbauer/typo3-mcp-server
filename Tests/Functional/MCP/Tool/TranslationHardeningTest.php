<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TranslationHardeningTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createMultiLanguageSiteConfiguration();
    }

    #[Test]
    public function translateIntoDefaultLanguageReturnsError(): void
    {
        $this->createAndSwitchToWorkspace('Translation WS');

        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['sys_language_uid' => 0, 'header' => 'Willkommen'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    #[Test]
    public function translateAlreadyTranslatedRecordReturnsError(): void
    {
        $this->createAndSwitchToWorkspace('Translation WS');

        $tool = GeneralUtility::makeInstance(WriteTableTool::class);

        $first = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['sys_language_uid' => 'de', 'header' => 'Willkommen'],
        ]);
        self::assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $duplicate = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['sys_language_uid' => 'de', 'header' => 'Willkommen'],
        ]);
        self::assertTrue($duplicate->isError, 'Expected error for duplicate translation');
        $text = $duplicate->content[0]->text;
        self::assertStringContainsString('Translation already exists', $text);
    }

    #[Test]
    public function translatedRecordIsVisibleByDefault(): void
    {
        $this->createAndSwitchToWorkspace('Translation WS');

        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['sys_language_uid' => 'de', 'header' => 'Willkommen'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text ?? '', true);
        self::assertIsArray($payload);
        self::assertFalse($payload['hidden']);
        self::assertSame('de', $payload['targetLanguage']);
        self::assertSame('testing', $payload['siteIdentifier']);

        $translationUid = (int)$payload['translationUid'];
        self::assertGreaterThan(0, $translationUid);

        $row = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('tt_content', $translationUid, 'hidden');
        self::assertIsArray($row);
        self::assertSame(0, (int)$row['hidden']);
    }

    #[Test]
    public function translateRespectsExplicitHiddenFlag(): void
    {
        $this->createAndSwitchToWorkspace('Translation WS');

        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'hidden' => true,
            'data' => ['sys_language_uid' => 'de', 'header' => 'Willkommen'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text ?? '', true);
        self::assertIsArray($payload);
        self::assertTrue($payload['hidden']);

        $translationUid = (int)$payload['translationUid'];
        $row = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('tt_content', $translationUid, 'hidden');
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['hidden']);
    }

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
            ],
        ];

        $siteDir = $this->instancePath . '/typo3conf/sites/testing/';
        if (!is_dir($siteDir)) {
            mkdir($siteDir, 0775, true);
        }
        file_put_contents($siteDir . 'config.yaml', Yaml::dump($siteConfiguration, 99, 2));
    }
}
