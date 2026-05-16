<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ApplyShadcnPresetToolTest extends AbstractFunctionalTest
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->getService(ToolRegistry::class);
        $tool = $registry->getTool('ApplyShadcnPreset');
        self::assertNotNull($tool, 'ApplyShadcnPreset tool must be registered.');
        $this->tool = $tool;
    }

    public function testAppliesPresetUrlWithPartialSelectionThroughShadcnCli(): void
    {
        [$fakeBin, $argsFile] = $this->createFakeNpx();
        $originalPath = getenv('PATH');
        $originalServerPath = $_SERVER['PATH'] ?? null;
        $originalEnvPath = $_ENV['PATH'] ?? null;

        putenv('FAKE_SHADCN_ARGS_FILE=' . $argsFile);
        $_SERVER['FAKE_SHADCN_ARGS_FILE'] = $argsFile;
        $_ENV['FAKE_SHADCN_ARGS_FILE'] = $argsFile;
        $testPath = $fakeBin . PATH_SEPARATOR . (is_string($originalPath) ? $originalPath : '');
        putenv('PATH=' . $testPath);
        $_SERVER['PATH'] = $testPath;
        $_ENV['PATH'] = $testPath;

        try {
            $result = $this->tool->execute([
                'preset' => 'https://ui.shadcn.com/create?preset=bkqYkPSa0',
                'only' => ['theme', 'font'],
                'packageManager' => 'npx',
            ]);
        } finally {
            putenv('FAKE_SHADCN_ARGS_FILE');
            unset($_SERVER['FAKE_SHADCN_ARGS_FILE'], $_ENV['FAKE_SHADCN_ARGS_FILE']);
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
            if ($originalServerPath === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $originalServerPath;
            }
            if ($originalEnvPath === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $originalEnvPath;
            }
        }

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        self::assertSame('applied', $data['status']);
        self::assertSame('bkqYkPSa0', $data['preset']);
        self::assertSame(['theme', 'font'], $data['only']);
        self::assertSame(0, $data['exitCode']);

        self::assertFileExists($argsFile);
        $arguments = file($argsFile, FILE_IGNORE_NEW_LINES);
        self::assertSame([
            'shadcn@latest',
            'apply',
            '--preset',
            'bkqYkPSa0',
            '--yes',
            '--only',
            'theme,font',
        ], $arguments);
    }

    public function testRejectsPresetCodeWithShellMetacharacters(): void
    {
        $result = $this->tool->execute([
            'preset' => 'bkqYkPSa0;rm -rf /',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Invalid shadcn preset', $this->getFirstTextContent($result));
    }

    public function testSchemaDocumentsPresetAndPartialApply(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'] ?? [];

        self::assertArrayHasKey('preset', $properties);
        self::assertArrayHasKey('only', $properties);
        self::assertArrayHasKey('packageManager', $properties);
        self::assertContains('preset', $schema['inputSchema']['required'] ?? []);
    }

    /**
     * @return array{string, string}
     */
    private function createFakeNpx(): array
    {
        $fakeBin = $this->instancePath . '/fake-bin';
        if (!is_dir($fakeBin)) {
            mkdir($fakeBin, 0777, true);
        }

        $argsFile = $this->instancePath . '/shadcn-args.txt';
        $script = <<<'SH_WRAP'
        #!/bin/sh
        for arg in "$@"; do
          printf '%s\n' "$arg" >> "$FAKE_SHADCN_ARGS_FILE"
        done
        printf 'preset applied\n'
        SH_WRAP;
        file_put_contents($fakeBin . '/npx', $script);
        chmod($fakeBin . '/npx', 0755);

        return [$fakeBin, $argsFile];
    }
}
