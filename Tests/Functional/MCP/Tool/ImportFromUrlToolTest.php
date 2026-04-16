<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ImportFromUrlTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ImportFromUrlToolTest extends AbstractFunctionalTest
{
    private ImportFromUrlTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(ImportFromUrlTool::class);
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $result = $this->tool->execute([
            'url' => 'file:///etc/passwd',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Only http and https URLs are allowed', $this->getFirstTextContent($result));
    }

    public function testRejectsPrivateIpv4Targets(): void
    {
        $result = $this->tool->execute([
            'url' => 'http://127.0.0.1/private',
            'targetPid' => 1,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('private/reserved IP address', $this->getFirstTextContent($result));
    }

    public function testRejectsInvalidModeBeforeFetching(): void
    {
        $result = $this->tool->execute([
            'url' => 'https://example.com/article',
            'targetPid' => 1,
            'mode' => 'preview',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('mode must be "analyze" or "execute"', $this->getFirstTextContent($result));
    }
}
