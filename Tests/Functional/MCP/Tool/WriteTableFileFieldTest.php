<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for WriteTable tool with file field (type=file) handling
 */
final class WriteTableFileFieldTest extends FunctionalTestCase
{
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

        $workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $this->tool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    public function testFileFieldRejectsScalarValue(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Page with bad media',
                'media' => 'not-an-array',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('File field must be an array', $result->content[0]->text);
    }

    public function testFileFieldRejectsNonIntegerUids(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Page with bad file refs',
                'media' => ['not-a-uid', 'also-not-a-uid'],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('UIDs', $result->content[0]->text);
    }

    public function testFileFieldRejectsObjectWithoutUid(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Page with incomplete file ref',
                'media' => [['title' => 'No uid provided']],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('uid', $result->content[0]->text);
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

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
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
                    ['uid' => 1, 'title' => 'My Image', 'alternative' => 'Alt text for image'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $json = json_decode($result->content[0]->text, true);
        $uid = $json['uid'] ?? 0;
        $this->assertGreaterThan(0, $uid);

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

        $this->assertNotEmpty($refs);

        $ref = $refs[0];
        $this->assertSame(1, (int) $ref['uid_local']);
        $this->assertSame('My Image', $ref['title']);
        $this->assertSame('Alt text for image', $ref['alternative']);
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

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}
