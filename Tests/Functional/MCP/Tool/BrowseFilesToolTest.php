<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\BrowseFilesTool;
use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

final class BrowseFilesToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function recursiveBrowseListsNestedFoldersAndRootFiles(): void
    {
        $writeTool = $this->get(WriteFileTool::class);

        $rootWrite = $writeTool->execute([
            'path' => 'browse/root.txt',
            'content' => 'root file',
        ]);
        self::assertFalse($rootWrite->isError, json_encode($rootWrite->jsonSerialize()));

        $nestedWrite = $writeTool->execute([
            'path' => 'browse/nested/deeper.txt',
            'content' => 'nested file',
        ]);
        self::assertFalse($nestedWrite->isError, json_encode($nestedWrite->jsonSerialize()));

        $browseTool = $this->get(BrowseFilesTool::class);
        $result = $browseTool->execute([
            'path' => 'browse/',
            'recursive' => true,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = (string)$result->content[0]->text;

        self::assertStringContainsString('FOLDER: /mcp/browse/', $content);
        self::assertStringContainsString('[DIR] nested', $content);
        self::assertStringContainsString('(1 files)', $content);
        self::assertStringContainsString('root.txt', $content);
    }

    #[Test]
    public function browsingMissingFolderReturnsError(): void
    {
        $browseTool = $this->get(BrowseFilesTool::class);
        $result = $browseTool->execute([
            'path' => 'missing-folder/',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Folder not found', (string)$result->content[0]->text);
    }

    #[Test]
    public function browsingOutsideHarnessIsRejected(): void
    {
        $browseTool = $this->get(BrowseFilesTool::class);
        $result = $browseTool->execute([
            'path' => '1:/user_upload/',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString(
            'restricted to the configured MCP harness',
            (string)$result->content[0]->text,
        );
    }
}
