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

        $this->assertToolError($result, 'url');
    }

    #[Test]
    public function rejectsFileSchemeUrls(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'file:///etc/passwd',
            'path' => 'evil.txt',
        ]);

        $this->assertToolError($result, 'Invalid URL format');
    }

    #[Test]
    public function rejectsPrivateIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://192.168.0.1/readme.txt',
        ]);

        $this->assertToolError($result, 'private or reserved');
    }

    #[Test]
    public function rejectsLoopbackIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://127.0.0.1/',
        ]);

        $this->assertToolError($result, 'private or reserved');
    }
}
