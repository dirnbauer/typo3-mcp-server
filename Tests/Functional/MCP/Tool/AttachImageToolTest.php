<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\Record\AttachImageTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(AttachImageTool::class)]
final class AttachImageToolTest extends FunctionalTestCase
{
    use GetServiceTrait;

    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z0f8AAAAASUVORK5CYII=';

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $workspaceContextService = $this->getService(WorkspaceContextService::class);
        $workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
    }

    public function testAttachSandboxImageToContentRecord(): void
    {
        $upload = $this->getService(UploadFileTool::class);
        $up = $upload->execute([
            'path' => 'images/attach-pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        self::assertFalse($up->isError, json_encode($up->jsonSerialize()));
        $ujson = json_decode((string)$up->content[0]->text, true);
        self::assertIsArray($ujson);
        $sysFileUid = (int)($ujson['uid'] ?? 0);
        self::assertGreaterThan(0, $sysFileUid);

        $write = $this->getService(WriteTableTool::class);
        $create = $write->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Attach image test',
            ],
        ]);
        self::assertFalse($create->isError, json_encode($create->jsonSerialize()));
        $cjson = json_decode((string)$create->content[0]->text, true);
        self::assertIsArray($cjson);
        $contentUid = (int)($cjson['uid'] ?? 0);
        self::assertGreaterThan(0, $contentUid);

        $tool = $this->getService(AttachImageTool::class);
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
            'field' => 'assets',
            'source' => ['sys_file_uid' => $sysFileUid],
            'mode' => 'replace',
            'reference' => [
                'alternative' => 'pixel test',
            ],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $json = json_decode((string)$result->content[0]->text, true);
        self::assertIsArray($json);
        self::assertSame([$sysFileUid], $json['attachedSysFileUids'] ?? []);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');
        $refs = $connection->createQueryBuilder()
            ->select('*')
            ->from('sys_file_reference')
            ->where('tablenames = :t', 'uid_foreign = :u', 'fieldname = :f')
            ->setParameter('t', 'tt_content')
            ->setParameter('u', $contentUid)
            ->setParameter('f', 'assets')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $refs);
        self::assertSame('pixel test', $refs[0]['alternative']);
    }
}
