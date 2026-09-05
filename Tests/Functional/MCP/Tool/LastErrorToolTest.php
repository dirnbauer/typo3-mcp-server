<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\LastErrorTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class LastErrorToolTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
        $logDirectory = Environment::getVarPath() . '/log';
        GeneralUtility::rmdir($logDirectory, true);
        GeneralUtility::mkdir_deep($logDirectory);
    }

    public function testNewestErrorUsesEntryTimestampInsteadOfFileModificationTime(): void
    {
        $directory = Environment::getVarPath() . '/log/';
        file_put_contents(
            $directory . 'typo3_older.log',
            "Tue, 01 Sep 2026 10:00:00 +0000 [ERROR] Old error\n"
            . "Sat, 05 Sep 2026 10:00:00 +0000 [INFO] Recent successful request\n"
        );
        file_put_contents(
            $directory . 'typo3_newer.log',
            "Fri, 04 Sep 2026 10:00:00 +0000 [CRITICAL] Newer error\nContinuation\n"
        );
        touch($directory . 'typo3_newer.log', 100);
        file_put_contents(
            $directory . 'typo3_deprecations.log',
            "Sat, 05 Sep 2026 11:00:00 +0000 [ERROR] Excluded deprecation\n"
        );

        $result = $this->getService(LastErrorTool::class)->execute(['full' => true]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode((string)$result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('typo3_newer.log', $payload['error']['file']);
        self::assertSame("Newer error\nContinuation", $payload['error']['message']);
    }

    public function testNonAdminCannotReadInstallationLogsEvenInLocalMode(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $this->getService(LastErrorTool::class)->execute(['full' => true]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', (string)$result->content[0]->text);
    }
}
