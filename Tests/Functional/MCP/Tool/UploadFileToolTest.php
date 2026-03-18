<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class UploadFileToolTest extends AbstractFunctionalTest
{
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    #[Test]
    public function uploadsFileIntoWorkspaceScopedHarnessFolder(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
            'metadata' => [
                'title' => 'Pixel',
                'alternative' => 'Single pixel image',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame('uploaded', $json['action']);
        self::assertGreaterThan(0, $json['workspaceId']);
        self::assertStringStartsWith('1:/mcp/workspaces/ws-' . $json['workspaceId'] . '/images/', $json['identifier']);
        self::assertSame('pixel.png', $json['originalFilename']);
        self::assertNotSame('pixel.png', $json['storedFilename']);
        self::assertSame('Pixel', $json['metadata']['title']);

        $storage = $this->get(StorageRepository::class)->findByUid(1);
        $file = $storage->getFile(substr((string)$json['identifier'], 2));
        self::assertSame('Pixel', $file->getMetaData()->get()['title']);
        self::assertSame('Single pixel image', $file->getMetaData()->get()['alternative']);
    }

    #[Test]
    public function rejectsInvalidBase64Payload(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'docs/broken.pdf',
            'content_base64' => 'not-base64',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('valid base64', $result->content[0]->text);
    }

    #[Test]
    public function rejectsUploadOutsideConfiguredHarness(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => '1:/user_upload/evil.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('restricted to the configured MCP harness', $result->content[0]->text);
    }

    #[Test]
    public function acceptsDataUrlPayloadWithoutBackendUserAndFallsBackToBaseHarness(): void
    {
        $originalBackendUser = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);

        try {
            $tool = $this->get(UploadFileTool::class);
            $result = $tool->execute([
                'path' => 'images/data-url.png',
                'content_base64' => 'data:image/png;base64,' . self::PIXEL_PNG_BASE64,
            ]);
        } finally {
            $GLOBALS['BE_USER'] = $originalBackendUser;
        }

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame(0, $json['workspaceId']);
        self::assertStringStartsWith('1:/mcp/images/', (string)$json['identifier']);
        self::assertSame('1:/mcp/', $json['uploadFolder']);
    }

    #[Test]
    public function rejectsUploadWithoutFilenameExtension(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/no-extension',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('filename extension', (string)$result->content[0]->text);
    }

    #[Test]
    public function ignoresUnknownMetadataKeys(): void
    {
        $tool = $this->get(UploadFileTool::class);
        $result = $tool->execute([
            'path' => 'images/known-metadata.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
            'metadata' => [
                'title' => 'Known title',
                'custom' => 'should be ignored',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);

        self::assertSame(['title' => 'Known title'], $json['metadata']);
    }
}
