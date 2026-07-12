<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\ContentAuditTool;
use Hn\McpServer\MCP\Tool\Record\GetPreviewUrlTool;
use Hn\McpServer\MCP\Tool\Record\RenderRecordTool;
use Hn\McpServer\MCP\Tool\Record\WorkspaceReviewTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Security boundary tests for non-admin editors with a restricted page-tree
 * entry point. Page 2 (About) is mounted; page 7 (News) is a disjoint branch.
 */
final class PageMountAuthorizationTest extends AbstractFunctionalTest
{
    private const EDITOR_UID = 99;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['perms_everybody' => 1],
            [],
        );
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'mount_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'db_mountpoints' => '2',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $this->authenticateRestrictedEditor();
    }

    public function testGetPageRejectsPageOutsideWebMount(): void
    {
        $result = $this->getService(GetPageTool::class)->execute(['uid' => 7]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
    }

    public function testGetPageAllowsPageInsideWebMount(): void
    {
        $result = $this->getService(GetPageTool::class)->execute(['uid' => 2]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('UID: 2', $this->getFirstTextContent($result));
        self::assertStringNotContainsString('News Header', $this->getFirstTextContent($result));
    }

    public function testPageTreeFromVirtualRootOnlyShowsMountedBranch(): void
    {
        $result = $this->getService(GetPageTreeTool::class)->execute([
            'startPage' => 0,
            'depth' => 2,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $tree = $this->getFirstTextContent($result);
        self::assertStringContainsString('[2] About Us', $tree);
        self::assertStringContainsString('[4] Our Team', $tree);
        self::assertStringNotContainsString('[1] Home', $tree);
        self::assertStringNotContainsString('[7] News', $tree);
        self::assertStringNotContainsString('[8] First Article', $tree);
    }

    public function testPageTreeRejectsExplicitRootOutsideWebMount(): void
    {
        $result = $this->getService(GetPageTreeTool::class)->execute([
            'startPage' => 7,
            'depth' => 2,
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
    }

    public function testPageTreeOmitsMountedDescendantWithoutPageShowPermission(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            [
                'perms_userid' => 0,
                'perms_user' => 0,
                'perms_groupid' => 0,
                'perms_group' => 0,
                'perms_everybody' => 0,
            ],
            ['uid' => 4],
        );

        $result = $this->getService(GetPageTreeTool::class)->execute([
            'startPage' => 0,
            'depth' => 2,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $tree = $this->getFirstTextContent($result);
        self::assertStringContainsString('[5] Mission', $tree);
        self::assertStringNotContainsString('[4] Our Team', $tree);
    }

    public function testContentAuditRejectsRootOutsideWebMount(): void
    {
        $result = $this->getService(ContentAuditTool::class)->execute([
            'rootPageId' => 7,
            'checks' => ['missing_meta_description'],
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
    }

    public function testContentAuditDefaultsToFirstReadableWebMount(): void
    {
        $result = $this->getService(ContentAuditTool::class)->execute([
            'checks' => ['missing_meta_description'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($this->getFirstTextContent($result), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['rootPageId']);
        foreach ($payload['issues']['missing_meta_description'] as $issue) {
            self::assertContains($issue['pageUid'], [2, 4, 5]);
        }
    }

    public function testContentSurfacesRequireTtContentReadPermission(): void
    {
        $GLOBALS['BE_USER']->groupData['tables_select'] = 'pages';

        $calls = [
            [$this->getService(GetPageTool::class), ['uid' => 2]],
            [$this->getService(ContentAuditTool::class), [
                'rootPageId' => 2,
                'checks' => ['missing_meta_description'],
            ]],
            [$this->getService(RenderRecordTool::class), [
                'pageId' => 2,
                'contentUid' => 102,
                'mode' => 'preview',
            ]],
        ];

        foreach ($calls as [$tool, $params]) {
            $result = $tool->execute($params);
            self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
            self::assertStringContainsString('tt_content', $this->getFirstTextContent($result));
        }

        // Tree navigation itself remains available and skips plugin queries
        // when tt_content is not selectable.
        $treeResult = $this->getService(GetPageTreeTool::class)->execute([
            'startPage' => 0,
            'depth' => 2,
        ]);
        self::assertFalse($treeResult->isError, json_encode($treeResult->jsonSerialize()));
    }

    public function testPreviewUrlRejectsPageAndContentOutsideWebMount(): void
    {
        $tool = $this->getService(GetPreviewUrlTool::class);

        foreach ([
            ['table' => 'pages', 'uid' => 7],
            ['table' => 'tt_content', 'uid' => 106],
        ] as $params) {
            $result = $tool->execute($params);
            self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
            self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
        }
    }

    public function testRenderRecordRejectsPageOutsideWebMountBeforeBuildingUrl(): void
    {
        $result = $this->getService(RenderRecordTool::class)->execute([
            'pageId' => 7,
            'mode' => 'preview',
        ]);

        self::assertTrue($result->isError, json_encode($result->jsonSerialize()));
        self::assertStringContainsString('permission', strtolower($this->getFirstTextContent($result)));
    }

    public function testWorkspaceReviewOnlyReturnsChangesInsideWebMount(): void
    {
        $workspaceId = 50;
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => $workspaceId,
            'pid' => 0,
            'title' => 'Mount Review Workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_' . self::EDITOR_UID,
            'deleted' => 0,
        ]);
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', [
            'uid' => 7002,
            'pid' => -1,
            'title' => 'Mounted draft',
            'doktype' => 1,
            't3ver_wsid' => $workspaceId,
            't3ver_oid' => 2,
            't3ver_state' => 0,
            'deleted' => 0,
        ]);
        $pages->insert('pages', [
            'uid' => 7007,
            'pid' => -1,
            'title' => 'Outside draft',
            'doktype' => 1,
            't3ver_wsid' => $workspaceId,
            't3ver_oid' => 7,
            't3ver_state' => 0,
            'deleted' => 0,
        ]);

        $this->switchToWorkspace($workspaceId);
        $result = $this->getService(WorkspaceReviewTool::class)->execute([
            'workspace_id' => $workspaceId,
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($this->getFirstTextContent($result), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['totalChanges']);
        self::assertSame('Mounted draft', $payload['changes']['pages'][0]['label']);
        self::assertStringNotContainsString('Outside draft', $this->getFirstTextContent($result));
    }

    private function authenticateRestrictedEditor(): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(self::EDITOR_UID);
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = '';
        $user->groupData['webmounts'] = '2';
        $user->user['db_mountpoints'] = '2';
        $user->user['admin'] = 0;
        // TYPO3's calcPerms() evaluates the everybody bit within the normal
        // group-permission branch, so a real editor context needs at least one
        // resolved backend group UID.
        $user->userGroupsUID = [1];
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }
}
