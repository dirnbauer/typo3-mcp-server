<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileFromUrlTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

/**
 * SSRF and URL validation for UploadFileFromUrl (no outbound HTTP required).
 */
final class UploadFileFromUrlToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function rejectsEmptyUrl(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute(['url' => '   ']);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('url', $result->content[0]->text);
    }

    #[Test]
    public function rejectsFileSchemeUrls(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'file:///etc/passwd',
            'path' => 'evil.txt',
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('Only http and https', $result->content[0]->text);
    }

    #[Test]
    public function rejectsPrivateIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://192.168.0.1/readme.txt',
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('private or reserved', $result->content[0]->text);
    }

    #[Test]
    public function rejectsLoopbackIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://127.0.0.1/',
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('private or reserved', $result->content[0]->text);
    }
}
