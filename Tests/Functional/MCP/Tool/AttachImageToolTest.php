<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\Record\AttachImageTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
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
    use DevSiteTestTrait;

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
        $this->disableDevSiteTools();

        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/sys_workspace.csv');
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

    public function testAttachImageRepairsMissingImageMetadataBeforeCreatingReference(): void
    {
        $upload = $this->getService(UploadFileTool::class);
        $up = $upload->execute([
            'path' => 'images/attach-metadata-pixel.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        self::assertFalse($up->isError, json_encode($up->jsonSerialize()));
        $ujson = json_decode((string)$up->content[0]->text, true);
        self::assertIsArray($ujson);
        $sysFileUid = (int)($ujson['uid'] ?? 0);
        self::assertGreaterThan(0, $sysFileUid);

        $metadataConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $metadataConnection->delete('sys_file_metadata', ['file' => $sysFileUid]);

        $write = $this->getService(WriteTableTool::class);
        $create = $write->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Attach metadata repair test',
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
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $metadata = $metadataConnection->createQueryBuilder()
            ->select('width', 'height')
            ->from('sys_file_metadata')
            ->where('file = :file')
            ->setParameter('file', $sysFileUid, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($metadata);
        self::assertSame(1, (int)$metadata['width']);
        self::assertSame(1, (int)$metadata['height']);
    }

    /**
     * When a content element has a workspace version row, sys_file_reference.uid_foreign must
     * be the version row uid — not t3ver_oid. Otherwise the reference does not belong to the
     * row DataHandler updates and the file field stays empty.
     */
    public function testAttachImageFileReferenceUidForeignMatchesWorkspaceVersionRow(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Fixtures/sys_file.csv');

        $write = $this->getService(WriteTableTool::class);
        $liveUid = 100;
        $touch = $write->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $liveUid,
            'data' => [
                'header' => 'Versioned in workspace for attach test',
            ],
        ]);
        self::assertFalse($touch->isError, json_encode($touch->jsonSerialize()));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        self::assertGreaterThan(0, $workspaceId, 'Test requires non-live workspace.');

        $versionRow = $connection->createQueryBuilder()
            ->select('uid')
            ->from('tt_content')
            ->where('t3ver_oid = :oid', 't3ver_wsid = :ws')
            ->setParameter('oid', $liveUid, ParameterType::INTEGER)
            ->setParameter('ws', $workspaceId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($versionRow) || (int)($versionRow['uid'] ?? 0) <= 0) {
            self::markTestSkipped('No workspace version row created for live tt_content:100; cannot assert uid_foreign fix.');
        }

        $versionUid = (int)$versionRow['uid'];
        self::assertNotSame(
            $liveUid,
            $versionUid,
            'Fixture should produce a version uid distinct from live uid to validate uid_foreign behavior.',
        );

        $upload = $this->getService(UploadFileTool::class);
        $up = $upload->execute([
            'path' => 'images/attach-ws-ref.png',
            'content_base64' => self::PIXEL_PNG_BASE64,
        ]);
        self::assertFalse($up->isError, json_encode($up->jsonSerialize()));
        $ujson = json_decode((string)$up->content[0]->text, true);
        self::assertIsArray($ujson);
        $sysFileUid = (int)($ujson['uid'] ?? 0);
        self::assertGreaterThan(0, $sysFileUid);

        $tool = $this->getService(AttachImageTool::class);
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
            'field' => 'assets',
            'source' => ['sys_file_uid' => $sysFileUid],
            'mode' => 'replace',
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $refConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');
        $refQb = $refConnection->createQueryBuilder();
        $refs = $refQb
            ->select('uid', 'uid_foreign', 'uid_local', 'fieldname', 'tablenames')
            ->from('sys_file_reference')
            ->where(
                $refQb->expr()->eq('tablenames', $refQb->createNamedParameter('tt_content')),
                $refQb->expr()->eq('fieldname', $refQb->createNamedParameter('assets')),
                $refQb->expr()->eq('uid_local', $refQb->createNamedParameter($sysFileUid, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertNotEmpty($refs, 'Expected a sys_file_reference for the attached sandbox file.');
        foreach ($refs as $ref) {
            self::assertSame(
                'tt_content',
                $ref['tablenames'],
            );
            self::assertSame(
                $versionUid,
                (int)($ref['uid_foreign'] ?? 0),
                'uid_foreign must be the workspace version row uid, not the live parent uid.',
            );
        }
    }
}
