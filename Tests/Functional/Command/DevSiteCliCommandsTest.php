<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Command;

use Hn\McpServer\Command\TcaResourceCommand;
use Hn\McpServer\Command\Tool\CreateLocallangToolCommand;
use Hn\McpServer\Command\Tool\GetViewHelperDocumentationToolCommand;
use Hn\McpServer\Command\Tool\ListViewHelpersToolCommand;
use Hn\McpServer\Command\Tool\SiteSettingsToolCommand;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class DevSiteCliCommandsTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        'fluid',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
    }

    public function testListViewHelpersCliReturnsJson(): void
    {
        $tester = new CommandTester($this->getService(ListViewHelpersToolCommand::class));
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertIsArray($payload['result']);
        self::assertNotEmpty($payload['result']['viewHelpers'] ?? null);
    }

    public function testGetViewHelperDocumentationCliUsesTagFromList(): void
    {
        $listTester = new CommandTester($this->getService(ListViewHelpersToolCommand::class));
        $listTester->execute(['--json' => true]);
        $listPayload = json_decode($listTester->getDisplay(), true);
        self::assertIsArray($listPayload);
        $tagName = $listPayload['result']['viewHelpers'][0]['tagName'] ?? '';
        self::assertNotSame('', $tagName);

        $docTester = new CommandTester($this->getService(GetViewHelperDocumentationToolCommand::class));
        $exitCode = $docTester->execute(['--tagName' => $tagName, '--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($docTester->getDisplay(), true);
        self::assertTrue($payload['ok']);
        self::assertStringContainsString($tagName, (string)$payload['result']);
        self::assertStringContainsString('XML Namespace', (string)$payload['result']);
    }

    public function testTcaResourceCliOverviewAndTable(): void
    {
        $overviewTester = new CommandTester($this->getService(TcaResourceCommand::class));
        $exitCode = $overviewTester->execute(['--json' => true]);
        self::assertSame(Command::SUCCESS, $exitCode);
        $overview = json_decode($overviewTester->getDisplay(), true);
        self::assertTrue($overview['ok']);
        self::assertStringContainsString('`pages`', (string)$overview['result']);

        $tableTester = new CommandTester($this->getService(TcaResourceCommand::class));
        $exitCode = $tableTester->execute(['--table' => 'pages', '--json' => true]);
        self::assertSame(Command::SUCCESS, $exitCode);
        $table = json_decode($tableTester->getDisplay(), true);
        self::assertTrue($table['ok']);
        self::assertStringContainsString('TABLE SCHEMA: pages', (string)$table['result']);
    }

    public function testCreateLocallangCliWritesXlf(): void
    {
        $fileName = 'locallang_cli_test_' . bin2hex(random_bytes(4)) . '.xlf';
        $targetFile = ExtensionManagementUtility::extPath('mcp_server') . 'Resources/Private/Language/' . $fileName;

        $tester = new CommandTester($this->getService(CreateLocallangToolCommand::class));
        $exitCode = $tester->execute([
            '--json' => true,
            '--extensionKey' => 'mcp_server',
            '--fileName' => $fileName,
            '--params' => json_encode([
                'transUnits' => [
                    ['id' => 'cli.label', 'source' => 'CLI label'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($targetFile);
        $payload = json_decode($tester->getDisplay(), true);
        self::assertTrue($payload['ok']);
    }

    public function testSiteSettingsCliIsBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();
        $tester = new CommandTester($this->getService(SiteSettingsToolCommand::class));
        $exitCode = $tester->execute([
            '--json' => true,
            '--action' => 'listDefinitions',
            '--identifier' => 'main',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        $payload = json_decode($tester->getDisplay(), true);
        self::assertFalse($payload['ok']);
    }

    protected function tearDown(): void
    {
        $languageDir = ExtensionManagementUtility::extPath('mcp_server') . 'Resources/Private/Language/';
        foreach (glob($languageDir . 'locallang_cli_test_*.xlf') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }
}
