<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\BrowseFilesTool;
use Hn\McpServer\MCP\Tool\File\BrowseFolderTool;
use Hn\McpServer\MCP\Tool\File\ListStoragesTool;
use Hn\McpServer\MCP\Tool\File\ReadFileMetadataTool;
use Hn\McpServer\MCP\Tool\File\SearchFileTool;
use Hn\McpServer\MCP\Tool\File\SearchMediaTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A restricted editor is mounted at 1:/allowed/ while 1:/secret/ exists in
 * the same storage. No file-facing tool may reveal the sibling folder.
 */
final class FileMountAuthorizationTest extends AbstractFunctionalTest
{
    private const EDITOR_UID = 99;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');
        $fileadmin = $this->instancePath . '/fileadmin';
        GeneralUtility::mkdir_deep($fileadmin . '/allowed');
        GeneralUtility::mkdir_deep($fileadmin . '/secret');
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin2');
        file_put_contents($fileadmin . '/allowed/mount-visible.txt', 'visible');
        file_put_contents($fileadmin . '/secret/mount-hidden.txt', 'hidden');

        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => 10,
            'pid' => 0,
            'title' => 'Allowed mount',
            'identifier' => '1:/allowed/',
            'sorting' => 10,
            'deleted' => 0,
            'hidden' => 0,
            'read_only' => 0,
        ]);
        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => 11,
            'pid' => 0,
            'title' => 'Secret mount',
            'identifier' => '1:/secret/',
            'sorting' => 20,
            'deleted' => 0,
            'hidden' => 0,
            'read_only' => 0,
        ]);

        $files = $this->connectionPool->getConnectionForTable('sys_file');
        $files->insert('sys_file', [
            'uid' => 9001,
            'pid' => 0,
            'name' => 'mount-visible.txt',
            'identifier' => '/allowed/mount-visible.txt',
            'storage' => 1,
            'type' => 1,
            'size' => 7,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'creation_date' => time(),
            'modification_date' => time(),
        ]);
        $files->insert('sys_file', [
            'uid' => 9002,
            'pid' => 0,
            'name' => 'mount-hidden.txt',
            'identifier' => '/secret/mount-hidden.txt',
            'storage' => 1,
            'type' => 1,
            'size' => 6,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'creation_date' => time(),
            'modification_date' => time(),
        ]);

        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'file_mount_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'file_mountpoints' => '10',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        $user = $this->setUpBackendUser(self::EDITOR_UID);
        $user->groupData['tables_select'] = 'sys_file,sys_file_metadata,sys_file_reference,sys_file_storage';
        $user->groupData['filemounts'] = '10';
        $user->user['file_mountpoints'] = '10';
        $user->user['admin'] = 0;
        $user->userGroupsUID = [1];
        $GLOBALS['BE_USER'] = $user;

        // Local mode deliberately relaxes the MCP sandbox, but it must never
        // relax the authenticated backend user's real FAL mounts.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
    }

    public function testSearchFileDoesNotReturnSiblingOutsideFileMount(): void
    {
        $result = $this->getService(SearchFileTool::class)->execute([
            'name' => 'mount-',
            'thumbnails' => false,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = implode("\n", array_map(
            static fn(object $content): string => (string)($content->text ?? ''),
            $result->content,
        ));
        self::assertStringContainsString('mount-visible.txt', $text);
        self::assertStringNotContainsString('mount-hidden.txt', $text);
        self::assertStringNotContainsString('/secret/', $text);
    }

    public function testSearchMediaAndItsTotalStayInsideFileMount(): void
    {
        $result = $this->getService(SearchMediaTool::class)->execute([
            'keyword' => 'mount-',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($this->getFirstTextContent($result), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['total']);
        self::assertSame(1, $payload['returned']);
        self::assertSame('mount-visible.txt', $payload['files'][0]['name']);
        self::assertStringNotContainsString('/secret/', $this->getFirstTextContent($result));
    }

    public function testListStoragesOnlyReturnsBackendUserStorages(): void
    {
        $result = $this->getService(ListStoragesTool::class)->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $this->getFirstTextContent($result);
        self::assertStringContainsString('Storage 1:', $text);
        self::assertStringNotContainsString('Storage 2:', $text);
        self::assertStringNotContainsString('Second Storage', $text);
        self::assertStringContainsString('Total: 1 storage(s)', $text);
    }

    public function testMetadataAndBrowsersRejectSiblingOutsideFileMount(): void
    {
        $calls = [
            [$this->getService(ReadFileMetadataTool::class), ['uid' => 9002]],
            [$this->getService(BrowseFolderTool::class), ['folder' => '1:/secret/']],
            [$this->getService(BrowseFilesTool::class), ['path' => '1:/secret/']],
        ];

        foreach ($calls as [$tool, $params]) {
            $result = $tool->execute($params);
            self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
            self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
            self::assertStringNotContainsString('mount-hidden.txt', $this->getFirstTextContent($result));
        }

        $browseResult = $this->getService(BrowseFolderTool::class)->execute([
            'folder' => '1:/allowed/',
        ]);
        self::assertFalse($browseResult->isError, json_encode($browseResult->jsonSerialize()));
        self::assertStringContainsString('mount-visible.txt', $this->getFirstTextContent($browseResult));
    }

    public function testMetadataAndSandboxBrowserAllowMountedFile(): void
    {
        $metadata = $this->getService(ReadFileMetadataTool::class)->execute(['uid' => 9001]);
        self::assertFalse($metadata->isError, json_encode($metadata->jsonSerialize()));
        self::assertStringContainsString('mount-visible.txt', $this->getFirstTextContent($metadata));

        $browse = $this->getService(BrowseFilesTool::class)->execute(['path' => '1:/allowed/']);
        self::assertFalse($browse->isError, json_encode($browse->jsonSerialize()));
        self::assertStringContainsString('mount-visible.txt', $this->getFirstTextContent($browse));
    }

    public function testFileToolsRequireSysFileTableReadPermission(): void
    {
        $GLOBALS['BE_USER']->groupData['tables_select'] = 'pages';

        $calls = [
            [$this->getService(SearchFileTool::class), ['name' => 'mount-', 'thumbnails' => false]],
            [$this->getService(SearchMediaTool::class), ['keyword' => 'mount-']],
            [$this->getService(ListStoragesTool::class), []],
            [$this->getService(ReadFileMetadataTool::class), ['uid' => 9001]],
            [$this->getService(BrowseFolderTool::class), ['folder' => '1:/allowed/']],
            [$this->getService(BrowseFilesTool::class), ['path' => '1:/allowed/']],
        ];

        foreach ($calls as [$tool, $params]) {
            $result = $tool->execute($params);
            self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
            self::assertStringNotContainsString('mount-visible.txt', $this->getFirstTextContent($result));
        }
    }

    public function testRootFileMountIsNormalizedAndAllowed(): void
    {
        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => 12,
            'pid' => 0,
            'title' => 'Storage root',
            'identifier' => '1:/',
            'sorting' => 30,
            'deleted' => 0,
            'hidden' => 0,
            'read_only' => 0,
        ]);
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        $GLOBALS['BE_USER']->groupData['filemounts'] = '12';
        $GLOBALS['BE_USER']->user['file_mountpoints'] = '12';

        $result = $this->getService(BrowseFolderTool::class)->execute(['folder' => '1:/']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('📂 /', $this->getFirstTextContent($result));
    }
}
