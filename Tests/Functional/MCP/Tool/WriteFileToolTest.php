<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\File\WriteFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class WriteFileToolTest extends AbstractFunctionalTest
{
    private const string PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    #[Test]
    public function createTextFileInDefaultStorage(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'notes/test-mcp.txt',
            'content' => 'Hello from MCP',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('created', $json['action']);
        self::assertSame('1:/mcp/notes/test-mcp.txt', $json['identifier']);
        self::assertGreaterThan(0, $json['uid']);

        $file = $this->storedFile('/mcp/notes/test-mcp.txt');
        self::assertSame('Hello from MCP', $file->getContents());
    }

    #[Test]
    public function overwriteExistingFile(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => 'notes/overwrite-me.txt',
            'content' => 'original',
        ]);
        self::assertFalse($first->isError, json_encode($first->jsonSerialize(), JSON_THROW_ON_ERROR));

        $second = $tool->execute([
            'path' => 'notes/overwrite-me.txt',
            'content' => 'replaced',
            'overwrite' => true,
        ]);
        self::assertFalse($second->isError, json_encode($second->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$second->content[0]->text, true);
        self::assertSame('overwritten', $json['action']);

        self::assertSame('replaced', $this->storedFile('/mcp/notes/overwrite-me.txt')->getContents());
    }

    #[Test]
    public function rejectsOverwriteWhenFlagNotSet(): void
    {
        $tool = $this->get(WriteFileTool::class);

        $first = $tool->execute([
            'path' => 'notes/no-overwrite.txt',
            'content' => 'original',
        ]);
        self::assertFalse($first->isError, json_encode($first->jsonSerialize(), JSON_THROW_ON_ERROR));

        $second = $tool->execute([
            'path' => 'notes/no-overwrite.txt',
            'content' => 'should fail',
        ]);
        self::assertTrue($second->isError);
        self::assertStringContainsString('already exists', $second->content[0]->text);
    }

    #[Test]
    public function rejectsDisallowedExtension(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'notes/evil.php',
            'content' => '<?php echo "nope";',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not allowed', $result->content[0]->text);
    }

    #[Test]
    public function rejectsDirectoryTraversalPath(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '../test.txt',
            'content' => 'test',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Directory traversal', $result->content[0]->text);
    }

    #[Test]
    public function createsParentFoldersAutomatically(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'deep/nested/data.json',
            'content' => '{"created": true}',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('created', $json['action']);

        self::assertTrue($this->defaultStorage()->hasFolder('/mcp/deep/nested/'));
        self::assertSame('{"created": true}', $this->storedFile('/mcp/deep/nested/data.json')->getContents());
    }

    #[Test]
    public function rejectsMissingPath(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'content' => 'test',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('path', $result->content[0]->text);
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

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));

        self::assertSame($jsonContent, $this->storedFile('/mcp/config.json')->getContents());
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

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('created', $json['action']);
        self::assertSame('My Document', $json['metadata']['title']);

        $file = $this->storedFile('/mcp/documented.txt');
        $meta = $file->getMetaData()->get();
        self::assertSame('My Document', $meta['title']);
        self::assertSame('A test document created via MCP', $meta['description']);
        self::assertSame('Document alt text', $meta['alternative']);
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

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('metadata_updated', $json['action']);
        self::assertSame('Updated Title', $json['metadata']['title']);

        $file = $this->storedFile('/mcp/keep-content.txt');
        self::assertSame('Original content stays', $file->getContents());
        self::assertSame('Updated Title', $file->getMetaData()->get()['title']);
    }

    #[Test]
    public function metadataOnlyRejectsNonexistentFile(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'does-not-exist.txt',
            'metadata' => ['title' => 'Nope'],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('not found', $result->content[0]->text);
    }

    #[Test]
    public function rejectsCallWithoutContentOrMetadata(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => 'empty-call.txt',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('content', $result->content[0]->text);
    }

    #[Test]
    public function rejectsWritesOutsideConfiguredSandbox(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/outside.txt',
            'content' => 'not allowed',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted to the configured MCP file sandbox', $result->content[0]->text);
    }

    #[Test]
    public function supportsAbsoluteSandboxIdentifierForTextWrites(): void
    {
        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => '1:/mcp/absolute/location.txt',
            'content' => 'absolute target',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('1:/mcp/absolute/location.txt', $json['identifier']);

        self::assertSame('absolute target', $this->storedFile('/mcp/absolute/location.txt')->getContents());
    }

    #[Test]
    public function metadataOnlyUpdateWorksForExistingUploadedImage(): void
    {
        $uploadTool = $this->get(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'path' => 'images/update-me.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        self::assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize(), JSON_THROW_ON_ERROR));

        $uploaded = json_decode((string)$uploadResult->content[0]->text, true);
        $identifier = (string)$uploaded['identifier'];
        $originalSize = (int)$uploaded['size'];

        $tool = $this->get(WriteFileTool::class);
        $result = $tool->execute([
            'path' => $identifier,
            'metadata' => [
                'title' => 'Updated image title',
                'alternative' => 'Updated image alt text',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertSame('metadata_updated', $json['action']);
        self::assertSame('Updated image title', $json['metadata']['title']);

        $file = $this->storedFile(substr($identifier, 2));
        self::assertSame($originalSize, $file->getSize());
        self::assertSame('Updated image title', $file->getMetaData()->get()['title']);
        self::assertSame('Updated image alt text', $file->getMetaData()->get()['alternative']);
    }

    private function defaultStorage(): ResourceStorage
    {
        $storage = $this->get(StorageRepository::class)->findByUid(1);
        self::assertInstanceOf(ResourceStorage::class, $storage, 'Storage 1 is missing');
        return $storage;
    }

    private function storedFile(string $identifier): File
    {
        $file = $this->defaultStorage()->getFile($identifier);
        self::assertInstanceOf(File::class, $file, $identifier . ' is not a file in storage 1');
        return $file;
    }
}
