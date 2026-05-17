<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for WriteTable tool with file field (type=file) handling
 */
final class WriteTableFileFieldTest extends FunctionalTestCase
{
    use GetServiceTrait;

    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';
    private const TINY_PDF_BASE64 = 'JVBERi0xLjEKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBvYmo8PC9UeXBlL1BhZ2VzL0NvdW50IDA+PmVuZG9iagp0cmFpbGVyPDwvUm9vdCAxIDAgUj4+CiUlRU9GCg==';

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/pages.csv');
        $this->setUpBackendUser(1);

        $workspaceContextService = $this->getService(WorkspaceContextService::class);
        $workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $this->tool = $this->getService(WriteTableTool::class);
    }

    public function testFileFieldRejectsScalarValue(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'allowRootLevelPageCreation' => true,
            'data' => [
                'title' => 'Page with bad media',
                'media' => 'not-an-array',
            ],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('Inline relation field must be an array', $result->content[0]->text);
    }

    public function testFileFieldRejectsNonIntegerUids(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'allowRootLevelPageCreation' => true,
            'data' => [
                'title' => 'Page with bad file refs',
                'media' => ['not-a-uid', 'also-not-a-uid'],
            ],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('uid_local', $result->content[0]->text);
    }

    public function testFileFieldRejectsObjectWithoutUid(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'allowRootLevelPageCreation' => true,
            'data' => [
                'title' => 'Page with incomplete file ref',
                'media' => [['title' => 'No uid provided']],
            ],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('uid', $result->content[0]->text);
    }

    public function testFileFieldAcceptsArrayOfUids(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/sys_file.csv');

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with files',
                'assets' => [1],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testFileFieldAcceptsObjectsWithUidAndMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/sys_file.csv');

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with file metadata',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'My Image', 'alternative' => 'Alt text for image'],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $json = json_decode((string)$result->content[0]->text, true);
        $uid = $json['uid'] ?? 0;
        self::assertGreaterThan(0, $uid);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');

        $refs = $connection->createQueryBuilder()
            ->select('*')
            ->from('sys_file_reference')
            ->where('tablenames = :table', 'fieldname = :field')
            ->setParameter('table', 'tt_content')
            ->setParameter('field', 'assets')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertNotEmpty($refs);

        $ref = $refs[0];
        self::assertSame(1, (int)$ref['uid_local']);
    }

    public function testFileFieldRepairsMissingImageMetadataBeforeCreatingReference(): void
    {
        $upload = $this->getService(UploadFileTool::class);
        $uploadResult = $upload->execute([
            'path' => 'images/write-table-metadata.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        self::assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));
        $uploadJson = json_decode((string)$uploadResult->content[0]->text, true);
        self::assertIsArray($uploadJson);
        $fileUid = (int)($uploadJson['uid'] ?? 0);
        self::assertGreaterThan(0, $fileUid);

        $metadataConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $metadataConnection->delete('sys_file_metadata', ['file' => $fileUid]);

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with repaired metadata',
                'assets' => [
                    ['uid_local' => $fileUid, 'alternative' => 'repaired metadata'],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $metadata = $metadataConnection->createQueryBuilder()
            ->select('width', 'height')
            ->from('sys_file_metadata')
            ->where('file = :file')
            ->setParameter('file', $fileUid)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($metadata);
        self::assertSame(1, (int)$metadata['width']);
        self::assertSame(1, (int)$metadata['height']);
    }

    public function testFileFieldKeepsPdfDownloadsAttachableWithoutImageDimensions(): void
    {
        $upload = $this->getService(UploadFileTool::class);
        $uploadResult = $upload->execute([
            'path' => 'docs/download.pdf',
            'content_base64' => self::TINY_PDF_BASE64,
            'metadata' => [
                'title' => 'Download PDF',
            ],
        ]);
        self::assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));
        $uploadJson = json_decode((string)$uploadResult->content[0]->text, true);
        self::assertIsArray($uploadJson);
        $fileUid = (int)($uploadJson['uid'] ?? 0);
        self::assertGreaterThan(0, $fileUid);
        self::assertSame('application/pdf', (string)($uploadJson['mimeType'] ?? ''));

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with PDF download',
                'assets' => [
                    ['uid_local' => $fileUid, 'title' => 'Download PDF'],
                ],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $metadataConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $metadata = $metadataConnection->createQueryBuilder()
            ->select('width', 'height')
            ->from('sys_file_metadata')
            ->where('file = :file')
            ->setParameter('file', $fileUid)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($metadata);
        self::assertSame(0, (int)$metadata['width']);
        self::assertSame(0, (int)$metadata['height']);
    }

    public function testFileFieldEmptyArrayCreatesNoReferences(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'No files',
                'assets' => [],
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}
