<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test file reference (sys_file_reference) support through MCP tools
 */
class FileReferenceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test reading a content element returns embedded file references
     */
    public function testReadContentElementWithFileReferences(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        // assets field should contain embedded file references
        self::assertArrayHasKey('assets', $record);
        self::assertIsArray($record['assets']);
        self::assertCount(2, $record['assets'], 'Should have 2 asset references');

        // Verify first file reference has expected fields
        $firstRef = $record['assets'][0];
        self::assertArrayHasKey('uid', $firstRef);
        self::assertArrayHasKey('uid_local', $firstRef);
        self::assertEquals('Hero Image', $firstRef['title']);
        self::assertEquals('The main hero image', $firstRef['description']);
        self::assertEquals('Homepage hero banner', $firstRef['alternative']);

        // Verify file metadata enrichment
        self::assertArrayHasKey('file_name', $firstRef);
        self::assertEquals('test.jpg', $firstRef['file_name']);
        self::assertArrayHasKey('file_identifier', $firstRef);
        self::assertEquals('/user_upload/test.jpg', $firstRef['file_identifier']);
        self::assertArrayHasKey('file_mime_type', $firstRef);
        self::assertEquals('image/jpeg', $firstRef['file_mime_type']);
    }

    /**
     * Test that foreign_match_fields prevent cross-contamination between file fields.
     * The assets field and media field should not mix up their file references.
     */
    public function testFileReferencesAreFieldScoped(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        // assets field should have exactly 2 references
        self::assertArrayHasKey('assets', $record);
        self::assertCount(2, $record['assets'], 'assets field should have 2 references');

        // media field should have exactly 1 reference
        self::assertArrayHasKey('media', $record);
        self::assertCount(1, $record['media'], 'media field should have 1 reference');

        // Verify the media reference is the correct one
        $mediaRef = $record['media'][0];
        self::assertEquals('Media File', $mediaRef['title']);
    }

    /**
     * Test creating a content element with file references
     */
    public function testCreateContentElementWithFileReferences(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a content element with an assets reference to existing sys_file uid=1
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content with assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Created Image', 'alternative' => 'Created alt text'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $responseData = json_decode((string)$result->content[0]->text, true);
        $contentUid = $responseData['uid'];

        // Read back and verify
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        self::assertArrayHasKey('assets', $record);
        self::assertCount(1, $record['assets']);

        $ref = $record['assets'][0];
        self::assertEquals('Created Image', $ref['title']);
        self::assertEquals('Created alt text', $ref['alternative']);
        self::assertEquals(1, $ref['uid_local']);

        // Verify file enrichment on the created reference
        self::assertEquals('test.jpg', $ref['file_name']);
    }

    /**
     * Test that file references work correctly in workspaces (live UIDs exposed)
     */
    public function testFileReferencesInWorkspace(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with file reference (this goes into a workspace)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Workspace content with assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Workspace Image'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Read it back - should show the file reference
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        self::assertArrayHasKey('assets', $record);
        self::assertNotEmpty($record['assets'], 'File references should be visible in workspace');
        self::assertEquals('Workspace Image', $record['assets'][0]['title']);
    }

    /**
     * Test updating file reference metadata (title, alt text, description)
     */
    public function testUpdateFileReferenceMetadata(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with a file reference
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content for update test',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Original Title', 'alternative' => 'Original Alt'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Update with new file references (replaces all)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Updated Title', 'alternative' => 'Updated Alt'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        self::assertCount(1, $record['assets']);
        self::assertEquals('Updated Title', $record['assets'][0]['title']);
        self::assertEquals('Updated Alt', $record['assets'][0]['alternative']);
    }

    /**
     * Test reading sys_file as a read-only table
     */
    public function testReadSysFileTable(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        self::assertNotEmpty($data['records']);

        $file = $data['records'][0];
        self::assertEquals(1, $file['uid']);
        self::assertEquals('test.jpg', $file['name']);
        self::assertEquals('/user_upload/test.jpg', $file['identifier']);
    }

    /**
     * Test that sys_file is read-only (writing should fail)
     */
    public function testSysFileIsReadOnly(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'name' => 'hacked.jpg',
            ],
        ]);
        self::assertTrue($result->isError, 'Writing to sys_file should fail');
    }

    /**
     * Test removing file references by updating with empty array
     */
    public function testRemoveFileReferences(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with file references
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content to clear assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'To be removed'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Update with empty assets array to remove all references
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back - should have no assets
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode((string)$result->content[0]->text, true);
        $record = $data['records'][0];

        self::assertArrayHasKey('assets', $record);
        self::assertEmpty($record['assets'], 'Asset references should be removed');
    }
}
