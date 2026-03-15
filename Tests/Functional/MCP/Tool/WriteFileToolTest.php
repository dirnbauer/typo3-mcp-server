<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class WriteFileToolTest extends AbstractFunctionalTest
{
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    #[Test]
    public function createTextFileInDefaultStorage(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'notes/test-mcp.txt',
            'content' => 'Hello from MCP',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('created', $json['action']);
        $this->assertSame('1:/mcp/notes/test-mcp.txt', $json['identifier']);
        $this->assertGreaterThan(0, $json['uid']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile('/mcp/notes/test-mcp.txt');
        $this->assertSame('Hello from MCP', $file->getContents());
    }

    #[Test]
    public function overwriteExistingFile(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => 'notes/overwrite-me.txt',
            'content' => 'original',
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $second = $tool->execute([
            'path' => 'notes/overwrite-me.txt',
            'content' => 'replaced',
            'overwrite' => true,
        ]);
        $this->assertFalse($second->isError, json_encode($second->jsonSerialize()));
        $json = json_decode((string) $second->content[0]->text, true);
        $this->assertSame('overwritten', $json['action']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertSame('replaced', $storage->getFile('/mcp/notes/overwrite-me.txt')->getContents());
    }

    #[Test]
    public function rejectsOverwriteWhenFlagNotSet(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => 'notes/no-overwrite.txt',
            'content' => 'original',
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $second = $tool->execute([
            'path' => 'notes/no-overwrite.txt',
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
            'path' => 'notes/evil.php',
            'content' => '<?php echo "nope";',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not allowed', $result->content[0]->text);
    }

    #[Test]
    public function rejectsDirectoryTraversalPath(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '../test.txt',
            'content' => 'test',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Directory traversal', $result->content[0]->text);
    }

    #[Test]
    public function createsParentFoldersAutomatically(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'deep/nested/data.json',
            'content' => '{"created": true}',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('created', $json['action']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertTrue($storage->hasFolder('/mcp/deep/nested/'));
        $this->assertSame('{"created": true}', $storage->getFile('/mcp/deep/nested/data.json')->getContents());
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
            'path' => 'config.json',
            'content' => $jsonContent,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertSame($jsonContent, $storage->getFile('/mcp/config.json')->getContents());
    }

    #[Test]
    public function setsMetadataOnNewFile(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'documented.txt',
            'content' => 'File with metadata',
            'metadata' => [
                'title' => 'My Document',
                'description' => 'A test document created via MCP',
                'alternative' => 'Document alt text',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('created', $json['action']);
        $this->assertSame('My Document', $json['metadata']['title']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile('/mcp/documented.txt');
        $meta = $file->getMetaData()->get();
        $this->assertSame('My Document', $meta['title']);
        $this->assertSame('A test document created via MCP', $meta['description']);
        $this->assertSame('Document alt text', $meta['alternative']);
    }

    #[Test]
    public function updatesMetadataOnExistingFileWithoutChangingContent(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $tool->execute([
            'path' => 'keep-content.txt',
            'content' => 'Original content stays',
        ]);

        $result = $tool->execute([
            'path' => 'keep-content.txt',
            'metadata' => [
                'title' => 'Updated Title',
                'description' => 'Added later',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('metadata_updated', $json['action']);
        $this->assertSame('Updated Title', $json['metadata']['title']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile('/mcp/keep-content.txt');
        $this->assertSame('Original content stays', $file->getContents());
        $this->assertSame('Updated Title', $file->getMetaData()->get()['title']);
    }

    #[Test]
    public function metadataOnlyRejectsNonexistentFile(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'does-not-exist.txt',
            'metadata' => ['title' => 'Nope'],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->content[0]->text);
    }

    #[Test]
    public function rejectsCallWithoutContentOrMetadata(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'empty-call.txt',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('content', $result->content[0]->text);
    }

    #[Test]
    public function rejectsWritesOutsideConfiguredHarness(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/outside.txt',
            'content' => 'not allowed',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('restricted to the configured MCP harness', $result->content[0]->text);
    }

    #[Test]
    public function supportsAbsoluteHarnessIdentifierForTextWrites(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/mcp/absolute/location.txt',
            'content' => 'absolute target',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('1:/mcp/absolute/location.txt', $json['identifier']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $this->assertSame('absolute target', $storage->getFile('/mcp/absolute/location.txt')->getContents());
    }

    #[Test]
    public function metadataOnlyUpdateWorksForExistingUploadedImage(): void
    {
        $uploadTool = $this->get(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'path' => 'images/update-me.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        $this->assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));

        $uploaded = json_decode((string) $uploadResult->content[0]->text, true);
        $identifier = (string) $uploaded['identifier'];
        $originalSize = (int) $uploaded['size'];

        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => $identifier,
            'metadata' => [
                'title' => 'Updated image title',
                'alternative' => 'Updated image alt text',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);
        $this->assertSame('metadata_updated', $json['action']);
        $this->assertSame('Updated image title', $json['metadata']['title']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile(substr($identifier, 2));
        $this->assertSame($originalSize, $file->getSize());
        $this->assertSame('Updated image title', $file->getMetaData()->get()['title']);
        $this->assertSame('Updated image alt text', $file->getMetaData()->get()['alternative']);
    }
}
