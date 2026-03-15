<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class WriteFileToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function createTextFileInDefaultStorage(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/test-mcp.txt',
            'content' => 'Hello from MCP',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode($result->content[0]->text, true);
        $this->assertSame('created', $json['action']);
        $this->assertSame('1:/user_upload/test-mcp.txt', $json['identifier']);
        $this->assertGreaterThan(0, $json['uid']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile('/user_upload/test-mcp.txt');
        $this->assertSame('Hello from MCP', $file->getContents());
    }

    #[Test]
    public function overwriteExistingFile(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => '1:/user_upload/overwrite-me.txt',
            'content' => 'original',
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $second = $tool->execute([
            'path' => '1:/user_upload/overwrite-me.txt',
            'content' => 'replaced',
            'overwrite' => true,
        ]);
        $this->assertFalse($second->isError, json_encode($second->jsonSerialize()));
        $json = json_decode($second->content[0]->text, true);
        $this->assertSame('overwritten', $json['action']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertSame('replaced', $storage->getFile('/user_upload/overwrite-me.txt')->getContents());
    }

    #[Test]
    public function rejectsOverwriteWhenFlagNotSet(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => '1:/user_upload/no-overwrite.txt',
            'content' => 'original',
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $second = $tool->execute([
            'path' => '1:/user_upload/no-overwrite.txt',
            'content' => 'should fail',
        ]);
        $this->assertTrue($second->isError);
        $this->assertStringContainsString('already exists', $second->content[0]->text);
    }

    #[Test]
    public function rejectsDisallowedExtension(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/evil.php',
            'content' => '<?php echo "nope";',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not allowed', $result->content[0]->text);
    }

    #[Test]
    public function rejectsInvalidPathFormat(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'fileadmin/test.txt',
            'content' => 'test',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid path format', $result->content[0]->text);
    }

    #[Test]
    public function createsParentFoldersAutomatically(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/mcp-test/deep/nested/data.json',
            'content' => '{"created": true}',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode($result->content[0]->text, true);
        $this->assertSame('created', $json['action']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertTrue($storage->hasFolder('/mcp-test/deep/nested/'));
        $this->assertSame('{"created": true}', $storage->getFile('/mcp-test/deep/nested/data.json')->getContents());
    }

    #[Test]
    public function rejectsMissingPath(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'content' => 'test',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('path', $result->content[0]->text);
    }

    #[Test]
    public function supportsJsonFile(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $jsonContent = json_encode(['name' => 'test', 'value' => 42], JSON_PRETTY_PRINT);

        $result = $tool->execute([
            'path' => '1:/user_upload/config.json',
            'content' => $jsonContent,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertSame($jsonContent, $storage->getFile('/user_upload/config.json')->getContents());
    }
}
