<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\CreateLocallangTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class CreateLocallangToolTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    private CreateLocallangTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
        $this->tool = $this->getService(CreateLocallangTool::class);
    }

    public function testCreatesXlfInLoadedExtension(): void
    {
        $fileName = 'locallang_mcp_test_' . bin2hex(random_bytes(4)) . '.xlf';
        $extensionPath = ExtensionManagementUtility::extPath('mcp_server');
        $targetFile = $extensionPath . 'Resources/Private/Language/' . $fileName;

        $result = $this->tool->execute([
            'extensionKey' => 'mcp_server',
            'fileName' => $fileName,
            'transUnits' => [
                ['id' => 'label.example', 'source' => 'Example label'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertFileExists($targetFile);
        $contents = file_get_contents($targetFile);
        self::assertIsString($contents);
        self::assertStringContainsString('label.example', $contents);
        self::assertStringContainsString('Example label', $contents);
    }

    protected function tearDown(): void
    {
        $languageDir = ExtensionManagementUtility::extPath('mcp_server') . 'Resources/Private/Language/';
        foreach (glob($languageDir . 'locallang_mcp_test_*.xlf') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }
}
