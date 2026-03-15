<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\ReadFileMetadataTool;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;

final class ReadFileMetadataToolTest extends AbstractFunctionalTest
{
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    #[Test]
    public function readsImageMetadataByAbsoluteIdentifier(): void
    {
        $uploadTool = $this->get(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'path' => 'images/metadata-pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
            'metadata' => [
                'title' => 'Pixel Title',
                'alternative' => 'Pixel alternative text',
            ],
        ]);
        $this->assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));

        $uploaded = json_decode((string) $uploadResult->content[0]->text, true);

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'identifier' => (string) $uploaded['identifier'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);

        $this->assertSame((string) $uploaded['identifier'], $json['identifier']);
        $this->assertSame('image/png', $json['mimeType']);
        $this->assertSame(1, $json['width']);
        $this->assertSame(1, $json['height']);
        $this->assertSame('Pixel Title', $json['metadata']['title']);
        $this->assertSame('Pixel alternative text', $json['metadata']['alternative']);
    }

    #[Test]
    public function returnsCategoriesAndUsageReferences(): void
    {
        $uploadTool = $this->get(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'path' => 'images/relations-pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        $this->assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));

        $uploaded = json_decode((string) $uploadResult->content[0]->text, true);
        $fileUid = (int) $uploaded['uid'];
        $now = time();

        $categoryConnection = $this->getConnectionForTable('sys_category');
        $categoryConnection->insert('sys_category', [
            'pid' => 0,
            'title' => 'Hero Images',
            'description' => 'Image category for metadata tests',
            'deleted' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'parent' => 0,
            'items' => 0,
        ]);
        $categoryUid = (int) $categoryConnection->lastInsertId();

        $this->getConnectionForTable('sys_category_record_mm')->insert('sys_category_record_mm', [
            'uid_local' => $categoryUid,
            'uid_foreign' => $fileUid,
            'tablenames' => 'sys_file_metadata',
            'fieldname' => 'categories',
            'sorting' => 1,
            'sorting_foreign' => 1,
        ]);

        $this->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'pid' => 1,
            'uid_local' => $fileUid,
            'uid_foreign' => 100,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'tstamp' => $now,
            'crdate' => $now,
        ]);

        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'uid' => $fileUid,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string) $result->content[0]->text, true);

        $this->assertSame('Hero Images', $json['categories'][0]['title']);
        $this->assertSame('tt_content', $json['usedIn'][0]['table']);
        $this->assertSame(100, $json['usedIn'][0]['uid']);
        $this->assertSame('image', $json['usedIn'][0]['field']);
    }

    #[Test]
    public function rejectsCallsWithoutUidOrIdentifier(): void
    {
        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Either uid or identifier must be provided', (string) $result->content[0]->text);
    }

    #[Test]
    public function rejectsIdentifierOutsideHarness(): void
    {
        $tool = $this->get(ReadFileMetadataTool::class);
        $result = $tool->execute([
            'identifier' => '1:/user_upload/outside.png',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString(
            'restricted to the configured MCP harness',
            (string) $result->content[0]->text,
        );
    }
}
