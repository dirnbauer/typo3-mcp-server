<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\CreateSiteTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CreateSiteToolTest extends AbstractFunctionalTest
{
    private CreateSiteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(CreateSiteTool::class);
    }

    public function testToolRequiresAdminPrivileges(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'some-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => [],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', $this->getFirstTextContent($result));
    }

    public function testCreateDerivesDefaultFlagsFromIsoCodes(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'default-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame('default-flags-site', $data['identifier']);
        self::assertSame('us', $data['config']['languages'][0]['flag']);
        self::assertSame('de', $data['config']['languages'][1]['flag']);
    }

    public function testCreateAllowsExplicitFlagOverride(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'explicit-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'defaultLanguage' => [
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'iso-639-1' => 'en',
                'flag' => 'gb',
            ],
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                    'flag' => 'at',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('gb', $data['config']['languages'][0]['flag']);
        self::assertSame('at', $data['config']['languages'][1]['flag']);
    }

    public function testCreateCanCreateRootPageBelowVisibleParent(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'new-website',
            'parentPageId' => $this->getRootPageUid(),
            'rootPageTitle' => 'New Website',
            'base' => 'https://new-website.example.com/',
            'dependencies' => ['vendor/site-package'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame('new-website', $data['identifier']);
        self::assertSame($this->getRootPageUid(), $data['rootPage']['parentPageId']);
        self::assertSame('New Website', $data['rootPage']['title']);
        self::assertSame('/new-website', $data['rootPage']['slug']);
        self::assertSame($data['rootPage']['uid'], $data['config']['rootPageId']);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');
        $createdPage = $connection
            ->select(['uid', 'pid', 'title', 'slug'], 'pages', ['uid' => (int)$data['rootPage']['uid']])
            ->fetchAssociative();

        self::assertIsArray($createdPage);
        self::assertSame($this->getRootPageUid(), (int)$createdPage['pid']);
        self::assertSame('New Website', $createdPage['title']);
        self::assertSame('/new-website', $createdPage['slug']);

        $config = $this->readSiteConfiguration('new-website');
        self::assertSame((int)$createdPage['uid'], $config['rootPageId']);
        self::assertSame('https://new-website.example.com/', $config['base']);
    }

    public function testCreateRootPageRejectsPidZeroParent(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'root-level-website',
            'parentPageId' => 0,
            'rootPageTitle' => 'Root Level Website',
            'base' => 'https://root-level.example.com/',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('pid=0 is intentionally rejected', $this->getFirstTextContent($result));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');
        $rootLevelRows = $connection
            ->select(['uid'], 'pages', ['pid' => 0, 'title' => 'Root Level Website'])
            ->fetchAllAssociative();

        self::assertSame([], $rootLevelRows);
    }

    public function testAddLanguagePreservesExistingSiteConfiguration(): void
    {
        $this->createExistingSiteConfiguration('existing-site', [
            [
                'title' => 'German',
                'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
        ]);
        $this->mergeSiteConfiguration('existing-site', [
            'websiteTitle' => 'Existing Site',
            'settings' => ['featureFlag' => true],
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
        ]);

        $result = $this->tool->execute([
            'action' => 'addLanguage',
            'identifier' => 'existing-site',
            'language' => [
                'title' => 'Chinese',
                'locale' => 'zh_CN.UTF-8',
                'iso-639-1' => 'zh',
                'base' => '/zh/',
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('languageAdded', $data['status']);
        self::assertSame(3, $data['totalLanguages']);

        $config = $this->readSiteConfiguration('existing-site');
        self::assertSame(1, $config['rootPageId']);
        self::assertSame('https://example.com/', $config['base']);
        self::assertSame('Existing Site', $config['websiteTitle']);
        self::assertTrue($config['settings']['featureFlag']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
        self::assertCount(3, $config['languages']);
        self::assertSame('zh', $config['languages'][2]['iso-639-1']);
        self::assertSame('/zh/', $config['languages'][2]['base']);
        self::assertSame('cn', $config['languages'][2]['flag']);
        self::assertSame(2, $config['languages'][2]['languageId']);
    }

    public function testReplaceLanguagesReplacesOnlyLanguageList(): void
    {
        $this->createExistingSiteConfiguration('replace-site', [
            [
                'title' => 'German',
                'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
            [
                'title' => 'French',
                'navigationTitle' => 'Français',
                'locale' => 'fr_FR.UTF-8',
                'iso-639-1' => 'fr',
                'base' => '/fr/',
            ],
        ]);
        $this->mergeSiteConfiguration('replace-site', [
            'websiteTitle' => 'Replace Site',
            'dependencies' => ['vendor/site-package'],
            'settings' => ['theme' => 'violet'],
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
            'languages' => [
                [
                    'title' => 'English',
                    'navigationTitle' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'flag' => 'us',
                    'hreflang' => 'en-us',
                ],
                [
                    'title' => 'German',
                    'navigationTitle' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'flag' => 'de',
                    'fallbackType' => 'strict',
                    'hreflang' => 'de-de',
                ],
                [
                    'title' => 'French',
                    'navigationTitle' => 'Français',
                    'enabled' => true,
                    'languageId' => 2,
                    'base' => '/fr/',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'flag' => 'fr',
                    'fallbackType' => 'fallback',
                ],
            ],
        ]);

        $result = $this->tool->execute([
            'action' => 'replaceLanguages',
            'identifier' => 'replace-site',
            'defaultLanguage' => [
                'title' => 'German',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
            ],
            'languages' => [
                [
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'base' => '/en/',
                ],
                [
                    'title' => 'Chinese',
                    'locale' => 'zh_CN.UTF-8',
                    'iso-639-1' => 'zh',
                    'base' => '/zh/',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('languagesReplaced', $data['status']);
        self::assertSame(3, $data['totalLanguages']);

        $config = $this->readSiteConfiguration('replace-site');
        self::assertSame(1, $config['rootPageId']);
        self::assertSame('https://example.com/', $config['base']);
        self::assertSame('Replace Site', $config['websiteTitle']);
        self::assertSame(['vendor/site-package'], $config['dependencies']);
        self::assertSame('violet', $config['settings']['theme']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
        self::assertCount(3, $config['languages']);

        self::assertSame('de', $config['languages'][0]['iso-639-1']);
        self::assertSame('/', $config['languages'][0]['base']);
        self::assertSame(0, $config['languages'][0]['languageId']);
        self::assertSame('Deutsch', $config['languages'][0]['navigationTitle']);
        self::assertSame('de-de', $config['languages'][0]['hreflang']);

        self::assertSame('en', $config['languages'][1]['iso-639-1']);
        self::assertSame('/en/', $config['languages'][1]['base']);
        self::assertSame(1, $config['languages'][1]['languageId']);
        self::assertSame('en-us', $config['languages'][1]['hreflang']);

        self::assertSame('zh', $config['languages'][2]['iso-639-1']);
        self::assertSame('/zh/', $config['languages'][2]['base']);
        self::assertSame(2, $config['languages'][2]['languageId']);
        self::assertSame('cn', $config['languages'][2]['flag']);
    }

    public function testCreateAcceptsDependenciesAndReturnsNoWarning(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'themed-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['vendor/site-package'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame(['vendor/site-package'], $data['config']['dependencies']);
        self::assertArrayNotHasKey('warning', $data);
    }

    public function testCreateMergesSetsAndDependencies(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'sets-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['vendor/a'],
            'sets' => ['vendor/b', 'vendor/a'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame(['vendor/a', 'vendor/b'], $data['config']['dependencies']);
    }

    public function testCreateWithoutRenderingCreatesGlobalTypoScriptInclude(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'no-render',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertArrayNotHasKey('warning', $data);
        self::assertSame('siteTypoScript', $data['renderingFallback']['type']);

        $setupPath = $this->getSiteConfigPath('no-render') . '/setup.typoscript';
        self::assertSame($setupPath, $data['renderingFallback']['path']);
        self::assertFileExists($setupPath);
        $setup = file_get_contents($setupPath);
        self::assertIsString($setup);
        self::assertStringContainsString('page = PAGE', $setup);
        self::assertStringContainsString('table = tt_content', $setup);
    }

    public function testCreateWithDependenciesDoesNotCreateGlobalTypoScriptInclude(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'rendered-by-set',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'dependencies' => ['vendor/site-package'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertArrayNotHasKey('renderingFallback', $data);
        self::assertFileDoesNotExist($this->getSiteConfigPath('rendered-by-set') . '/setup.typoscript');
    }

    public function testUpdateMergesDependenciesWhilePreservingOtherKeys(): void
    {
        $this->createExistingSiteConfiguration('update-site', [
            [
                'title' => 'German',
                'locale' => 'de_DE.UTF-8',
                'iso-639-1' => 'de',
                'base' => '/de/',
            ],
        ]);
        $this->mergeSiteConfiguration('update-site', [
            'websiteTitle' => 'Existing Site',
            'routeEnhancers' => ['PageTypeSuffix' => ['type' => 'PageType']],
        ]);

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'update-site',
            'dependencies' => ['vendor/theme'],
            'settings' => ['theme' => 'dark'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('updated', $data['status']);
        self::assertContains('dependencies', $data['appliedKeys']);
        self::assertContains('settings', $data['appliedKeys']);

        $config = $this->readSiteConfiguration('update-site');
        self::assertSame(['vendor/theme'], $config['dependencies']);
        self::assertSame('dark', $config['settings']['theme']);
        // Unrelated keys are preserved.
        self::assertSame('Existing Site', $config['websiteTitle']);
        self::assertSame('PageType', $config['routeEnhancers']['PageTypeSuffix']['type']);
    }

    public function testUpdateRejectsEmptyRequest(): void
    {
        $this->createExistingSiteConfiguration('empty-update-site', []);

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'empty-update-site',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('dependencies, sets, settings, or config', $this->getFirstTextContent($result));
    }

    public function testUpdateAcceptsGenericConfigAndProtectsStructuralKeys(): void
    {
        $this->createExistingSiteConfiguration('protect-site', []);
        $before = $this->readSiteConfiguration('protect-site');

        $result = $this->tool->execute([
            'action' => 'update',
            'identifier' => 'protect-site',
            'config' => [
                'errorHandling' => [['errorCode' => 404]],
                'rootPageId' => 9999,
                'base' => 'https://malicious.example/',
                'languages' => [],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('updated', $data['status']);
        self::assertContains('errorHandling', $data['appliedKeys']);

        $after = $this->readSiteConfiguration('protect-site');
        self::assertSame($before['rootPageId'], $after['rootPageId']);
        self::assertSame($before['base'], $after['base']);
        self::assertCount(count($before['languages']), $after['languages']);
        self::assertSame([['errorCode' => 404]], $after['errorHandling']);
    }

    public function testCreateAddsRootPageToRestrictedWorkspaceMountpoints(): void
    {
        // Workspace restricted to the "About" subtree (page 2), not the new site root.
        $workspaceId = $this->createWorkspaceRecord('2');

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'mounted-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://mounted.example.com/',
            'dependencies' => ['vendor/theme'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertArrayHasKey('access', $data);

        $updatedIds = array_column($data['access']['workspaces']['updated'], 'id');
        self::assertContains($workspaceId, $updatedIds, 'Restricted workspace should be reported as updated');

        $mounts = $this->readCsvColumn('sys_workspace', $workspaceId);
        self::assertContains($this->getRootPageUid(), $mounts, 'New site root page should be added as a starting point');
        self::assertContains(2, $mounts, 'Existing mountpoint must be preserved');
    }

    public function testCreateLeavesUnrestrictedWorkspaceMountpointsUntouched(): void
    {
        $emptyWorkspaceId = $this->createWorkspaceRecord('');
        $rootMountedWorkspaceId = $this->createWorkspaceRecord('0');

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'unrestricted-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://unrestricted.example.com/',
            'dependencies' => ['vendor/theme'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);

        // Unrestricted workspaces must not gain the new root page (empty/0 already reaches it).
        self::assertSame([], $this->readCsvColumn('sys_workspace', $emptyWorkspaceId));
        self::assertSame([0], $this->readCsvColumn('sys_workspace', $rootMountedWorkspaceId));

        // Both unrestricted workspaces are reported as skipped for that reason.
        self::assertGreaterThanOrEqual(2, $data['access']['workspaces']['skipped']['unrestricted'] ?? 0);
    }

    public function testCreateDoesNotDuplicateAlreadyMountedRootPage(): void
    {
        $workspaceId = $this->createWorkspaceRecord((string)$this->getRootPageUid());

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'already-mounted-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://already-mounted.example.com/',
            'dependencies' => ['vendor/theme'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);

        self::assertSame([$this->getRootPageUid()], $this->readCsvColumn('sys_workspace', $workspaceId), 'Root page must not be duplicated');
        self::assertGreaterThanOrEqual(1, $data['access']['workspaces']['skipped']['alreadyMounted'] ?? 0);
    }

    public function testCreateProvisionsDedicatedEditorGroupMountedAtRoot(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'editor-group-site',
            'parentPageId' => $this->getRootPageUid(),
            'rootPageTitle' => 'Editor Group Site',
            'base' => 'https://editor-group.example.com/',
            'dependencies' => ['vendor/theme'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);

        $rootPageId = (int)$data['rootPage']['uid'];
        $group = $data['access']['editorGroup'];
        self::assertTrue($group['created']);
        self::assertSame('Editors: Editor Group Site', $group['title']);
        self::assertSame($rootPageId, $group['mountpoint']);

        // The group is scoped to the new site root and can edit content.
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->select(['db_mountpoints', 'tables_modify', 'groupMods', 'explicit_allowdeny'], 'be_groups', ['uid' => (int)$group['id']])
            ->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame((string)$rootPageId, $row['db_mountpoints']);
        self::assertStringContainsString('tt_content', (string)$row['tables_modify']);
        self::assertStringContainsString('web_layout', (string)$row['groupMods']);
        self::assertStringContainsString('tt_content:CType:', (string)$row['explicit_allowdeny']);

        // The newly created root page is owned by the editor group with full perms.
        self::assertTrue($data['access']['pagePermissions']['granted']);
        $page = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->select(['perms_groupid', 'perms_group'], 'pages', ['uid' => $rootPageId])
            ->fetchAssociative();
        self::assertSame((int)$group['id'], (int)$page['perms_groupid']);
        self::assertSame(31, (int)$page['perms_group']);
    }

    public function testCreateSeedsNamedEditorsIntoGroupAndSkipsAdminAndUnknown(): void
    {
        $aliceId = $this->createBackendUserRecord('alice', false, '');

        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'seeded-editor-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://seeded.example.com/',
            'dependencies' => ['vendor/theme'],
            'editors' => ['alice', 'admin', 'ghost'],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);

        $groupId = (int)$data['access']['editorGroup']['id'];
        self::assertContains('alice', $data['access']['editors']['added']);
        self::assertContains($groupId, $this->readCsvColumn('be_users', $aliceId, 'usergroup'), 'alice should be a member of the editor group');

        // admin user is skipped (ignores mounts), unknown username is skipped.
        self::assertGreaterThanOrEqual(1, $data['access']['editors']['skipped']['admin'] ?? 0);
        self::assertGreaterThanOrEqual(1, $data['access']['editors']['skipped']['notFound'] ?? 0);
    }

    public function testCreateReusesExistingEditorGroup(): void
    {
        $params = [
            'action' => 'create',
            'identifier' => 'idempotent-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://idempotent.example.com/',
            'dependencies' => ['vendor/theme'],
        ];

        $first = $this->extractJsonFromResult($this->tool->execute($params));
        self::assertTrue($first['access']['editorGroup']['created']);

        $second = $this->extractJsonFromResult($this->tool->execute($params));
        self::assertFalse($second['access']['editorGroup']['created'], 'Second create must reuse the existing group');
        self::assertSame($first['access']['editorGroup']['id'], $second['access']['editorGroup']['id']);

        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->count('uid', 'be_groups', ['title' => $first['access']['editorGroup']['title'], 'deleted' => 0]);
        self::assertSame(1, $count, 'Only one editor group should exist for the site');
    }

    private function createWorkspaceRecord(string $dbMountpoints): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'pid' => 0,
            'title' => 'Test Workspace ' . $dbMountpoints,
            'description' => 'Workspace for mountpoint sync testing',
            'adminusers' => '1',
            'members' => '',
            'db_mountpoints' => $dbMountpoints,
            'file_mountpoints' => '',
            'publish_time' => 0,
            'publish_access' => 0,
            'stagechg_notification' => 0,
            'deleted' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function createBackendUserRecord(string $username, bool $admin, string $dbMountpoints): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => $username,
            'password' => '',
            'admin' => $admin ? 1 : 0,
            'db_mountpoints' => $dbMountpoints,
            'deleted' => 0,
            'disable' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<int>
     */
    private function readCsvColumn(string $table, int $uid, string $column = 'db_mountpoints'): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);
        $value = $connection
            ->select([$column], $table, ['uid' => $uid])
            ->fetchOne();

        $out = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $out[] = (int)$part;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $languages
     */
    private function createExistingSiteConfiguration(string $identifier, array $languages): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => $identifier,
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => $languages,
        ]);
        $this->assertSuccessfulToolResult($result);
        $this->flushSiteConfigurationCache();
    }

    /**
     * @param array<string, mixed> $siteConfiguration
     */
    private function mergeSiteConfiguration(string $identifier, array $siteConfiguration): void
    {
        $config = $this->readSiteConfiguration($identifier);
        $mergedConfiguration = array_replace_recursive($config, $siteConfiguration);
        $configPath = $this->getSiteConfigPath($identifier);
        GeneralUtility::writeFile($configPath . '/config.yaml', Yaml::dump($mergedConfiguration, 99, 2), true);
        $this->flushSiteConfigurationCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function readSiteConfiguration(string $identifier): array
    {
        $config = Yaml::parseFile($this->getSiteConfigPath($identifier) . '/config.yaml');
        self::assertIsArray($config);

        return $config;
    }

    private function getSiteConfigPath(string $identifier): string
    {
        /** @var SiteConfiguration $siteConfiguration */
        $siteConfiguration = $this->getService(SiteConfiguration::class);
        $paths = $siteConfiguration->getAllSiteConfigurationPaths();
        self::assertArrayHasKey($identifier, $paths);

        return $paths[$identifier];
    }

    private function flushSiteConfigurationCache(): void
    {
        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            if ($cacheManager->hasCache('core')) {
                $cacheManager->getCache('core')->remove('sites-configuration');
            }
            if ($cacheManager->hasCache('runtime')) {
                $cacheManager->getCache('runtime')->remove('sites-configuration');
            }
        } catch (\Throwable) {
            // Ignore cache errors during functional tests.
        }
    }
}
