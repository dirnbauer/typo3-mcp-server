<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

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
