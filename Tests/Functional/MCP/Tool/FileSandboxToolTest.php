<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\BrowseFilesTool;
use Hn\McpServer\MCP\Tool\File\ReadFileMetadataTool;
use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class FileSandboxToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function browseWithoutPathDescribesSandbox(): void
    {
        $tool = $this->get(BrowseFilesTool::class);
        $result = $tool->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('MCP FILE SANDBOX', $result->content[0]->text);
        self::assertStringContainsString('1:/mcp/', $result->content[0]->text);
    }

    #[Test]
    public function readMetadataSupportsRelativePathInsideSandbox(): void
    {
        $writeTool = $this->get(WriteFileTool::class);
        $writeResult = $writeTool->execute([
            'path' => 'images/inside.txt',
            'content' => 'inside sandbox',
        ]);
        self::assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'identifier' => 'images/inside.txt',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('1:/mcp/images/inside.txt', $json['identifier']);
        self::assertSame('txt', $json['extension']);
    }

    #[Test]
    public function readMetadataRejectsFilesOutsideSandboxEvenByUid(): void
    {
        $storage = $this->get(StorageRepository::class)->findByUid(1);
        if (!$storage->hasFolder('/outside-sandbox/')) {
            $storage->createFolder('/outside-sandbox/');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'mcp-sandbox-');
        file_put_contents($temporaryFile, 'outside');

        try {
            $file = $storage->addFile($temporaryFile, $storage->getFolder('/outside-sandbox/'), 'outside.txt');
        } finally {
            if (file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'uid' => $file->getUid(),
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted to the configured MCP file sandbox', $result->content[0]->text);
    }
}
