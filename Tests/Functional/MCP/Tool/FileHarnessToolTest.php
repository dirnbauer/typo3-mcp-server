<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\BrowseFilesTool;
use Hn\McpServer\MCP\Tool\File\ReadFileMetadataTool;
use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class FileHarnessToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function browseWithoutPathDescribesHarness(): void
    {
        $tool = $this->get(BrowseFilesTool::class);
        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('MCP FILE HARNESS', $result->content[0]->text);
        $this->assertStringContainsString('1:/mcp/', $result->content[0]->text);
    }

    #[Test]
    public function readMetadataSupportsRelativePathInsideHarness(): void
    {
        $writeTool = $this->get(WriteFileTool::class);
        $writeResult = $writeTool->execute([
            'path' => 'images/inside.txt',
            'content' => 'inside harness',
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'identifier' => 'images/inside.txt',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('1:/mcp/images/inside.txt', $json['identifier']);
        $this->assertSame('txt', $json['extension']);
    }

    #[Test]
    public function readMetadataRejectsFilesOutsideHarnessEvenByUid(): void
    {
        $storage = $this->get(StorageRepository::class)->findByUid(1);
        if (!$storage->hasFolder('/outside-harness/')) {
            $storage->createFolder('/outside-harness/');
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'mcp-harness-');
        file_put_contents($temporaryFile, 'outside');

        try {
            $file = $storage->addFile($temporaryFile, $storage->getFolder('/outside-harness/'), 'outside.txt');
        } finally {
            if (file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'uid' => $file->getUid(),
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('restricted to the configured MCP harness', $result->content[0]->text);
    }
}
