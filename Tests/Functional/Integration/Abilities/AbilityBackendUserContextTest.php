<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Integration\Abilities;

use Hn\McpServer\Integration\Abilities\ExecuteMcpToolAbility;
use Hn\McpServer\Integration\Abilities\ListMcpSkillsAbility;
use Hn\McpServer\MCP\SkillRegistry;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\AbilityBackendUserContextService;
use Hn\McpServer\Service\McpToolCatalogService;
use Hn\McpServer\Service\ToolResultNormalizer;
use Hn\McpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Domain\ExecutionContext;

final class AbilityBackendUserContextTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private ConnectionPool $connectionPool;
    private AbilityBackendUserContextService $backendUserContext;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->backendUserContext = new AbilityBackendUserContextService(
            $this->connectionPool,
            GeneralUtility::makeInstance(Context::class),
            GeneralUtility::makeInstance(WorkspaceContextService::class),
            GeneralUtility::makeInstance(LanguageServiceFactory::class),
        );
    }

    #[Test]
    public function executeToolPermissionBuildsSessionPermissionsAndWritableWorkspaceContext(): void
    {
        $this->createBackendGroup(10);
        $this->createBackendUser(2, userGroup: '10', uc: serialize(['startModule' => 'web_layout']));
        $this->createWorkspace(10, members: 'be_users_2');
        $backendUser = $this->createRawBackendUser(2);

        $permission = $this->createExecuteAbility()->checkPermission([], $this->restContext());

        self::assertTrue($permission, is_string($permission) ? $permission : 'Permission denied.');
        self::assertSame(2, (int)($backendUser->user['uid'] ?? 0));
        self::assertContains(10, $backendUser->userGroupsUID);
        self::assertStringContainsString('tt_content', (string)($backendUser->groupData['tables_modify'] ?? ''));
        self::assertSame('web_layout', $backendUser->uc['startModule'] ?? null);

        // This is the session operation that crashes DataHandler-adjacent paths
        // when sg_apicore token authentication leaves userSession uninitialized.
        $backendUser->setAndSaveSessionData('mcp-ability-test', 'ready');
        self::assertSame('ready', $backendUser->getSessionData('mcp-ability-test'));

        $backendAspect = GeneralUtility::makeInstance(Context::class)->getAspect('backend.user');
        self::assertInstanceOf(UserAspect::class, $backendAspect);
        self::assertSame(2, $backendAspect->get('id'));
        self::assertTrue($backendAspect->get('isLoggedIn'));

        $workspaceAspect = GeneralUtility::makeInstance(Context::class)->getAspect('workspace');
        self::assertInstanceOf(WorkspaceAspect::class, $workspaceAspect);
        self::assertSame(10, $workspaceAspect->getId());
        self::assertSame(10, $backendUser->workspace);
    }

    #[Test]
    public function readOnlyAbilityRequiresActiveUserWithoutCreatingWorkspace(): void
    {
        $this->createBackendUser(2);
        $backendUser = $this->createRawBackendUser(2);
        $before = $this->countWorkspaces();
        $ability = new ListMcpSkillsAbility(
            new SkillRegistry(dirname(__DIR__, 4) . '/Resources/Private/Skills'),
            $this->backendUserContext,
        );

        $permission = $ability->checkPermission([], $this->restContext());

        self::assertTrue($permission, is_string($permission) ? $permission : 'Permission denied.');
        self::assertSame($before, $this->countWorkspaces());
        $backendUser->setSessionData('mcp-read-ability-test', 'ready');
        self::assertSame('ready', $backendUser->getSessionData('mcp-read-ability-test'));
    }

    #[Test]
    public function readOnlyAbilityRejectsDisabledDatabaseUser(): void
    {
        $this->createBackendUser(2, disabled: true);
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 2, 'username' => 'disabled_ability_user'];
        $GLOBALS['BE_USER'] = $backendUser;
        $ability = new ListMcpSkillsAbility(
            new SkillRegistry(dirname(__DIR__, 4) . '/Resources/Private/Skills'),
            $this->backendUserContext,
        );

        $permission = $ability->checkPermission([], $this->restContext());

        self::assertIsString($permission);
        self::assertStringContainsString('active TYPO3 backend user', $permission);
    }

    #[Test]
    public function executeToolPermissionUsesLiveReadContextWithoutCreatingWorkspace(): void
    {
        $this->createBackendUser(2);
        $backendUser = $this->createRawBackendUser(2);

        $permission = $this->createExecuteAbility()->checkPermission([], $this->restContext());

        self::assertTrue($permission, is_string($permission) ? $permission : 'Permission denied.');
        self::assertSame(0, $this->countWorkspaces());
        self::assertSame(0, $backendUser->workspace);
        $workspaceAspect = GeneralUtility::makeInstance(Context::class)->getAspect('workspace');
        self::assertInstanceOf(WorkspaceAspect::class, $workspaceAspect);
        self::assertSame(0, $workspaceAspect->getId());
    }

    #[Test]
    public function executeToolAbilityRunsDataHandlerWriteInValidatedDraft(): void
    {
        $this->createBackendUser(1, admin: true);
        $this->createWorkspace(10, members: '');
        $this->createPage(1, 'Live title');
        $this->createRawBackendUser(1);
        $ability = $this->createExecuteAbility();

        $permission = $ability->checkPermission([], $this->restContext());
        self::assertTrue($permission, is_string($permission) ? $permission : 'Permission denied.');

        $result = $ability->execute([
            'name' => 'WriteTable',
            'arguments' => [
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => ['title' => 'Ability draft title'],
            ],
        ], $this->restContext());

        self::assertIsArray($result);
        self::assertFalse((bool)($result['isError'] ?? false), json_encode($result));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $draft = $queryBuilder
            ->select('title', 't3ver_wsid', 't3ver_oid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(10)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(1)),
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($draft);
        self::assertSame('Ability draft title', $draft['title'] ?? null);
        self::assertSame(10, (int)($draft['t3ver_wsid'] ?? 0));
        self::assertSame('Live title', $this->connectionPool->getConnectionForTable('pages')->fetchOne(
            'SELECT title FROM pages WHERE uid = ?',
            [1],
        ));
    }

    private function createExecuteAbility(): ExecuteMcpToolAbility
    {
        $toolRegistry = $this->getContainer()->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $toolRegistry);

        return new ExecuteMcpToolAbility(
            new McpToolCatalogService($toolRegistry, new ToolResultNormalizer()),
            $this->backendUserContext,
        );
    }

    private function restContext(): ExecutionContext
    {
        return new ExecutionContext(
            surface: ExecutionContext::SURFACE_REST,
            grantedScopes: ['mcp:tools:execute', 'mcp:skills:read'],
        );
    }

    private function createRawBackendUser(int $uid): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($uid);
        self::assertIsArray($backendUser->user);
        $GLOBALS['BE_USER'] = $backendUser;

        return $backendUser;
    }

    private function createBackendUser(
        int $uid,
        string $userGroup = '',
        string $uc = '',
        bool $disabled = false,
        bool $admin = false,
    ): void {
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'pid' => 0,
            'username' => 'ability_user_' . $uid,
            'password' => '',
            'admin' => $admin ? 1 : 0,
            'disable' => $disabled ? 1 : 0,
            'deleted' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'usergroup' => $userGroup,
            'workspace_id' => 0,
            'workspace_perms' => 0,
            'userMods' => '',
            'uc' => $uc,
            'lang' => 'default',
            'tstamp' => time(),
            'crdate' => time(),
        ]);
    }

    private function createBackendGroup(int $uid): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid,
            'pid' => 0,
            'title' => 'Ability editors',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => 'pages,tt_content',
            'pagetypes_select' => '1',
            'workspace_perms' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    private function createWorkspace(int $uid, string $members): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => $uid,
            'pid' => 0,
            'title' => 'Ability workspace',
            'adminusers' => '',
            'members' => $members,
            'deleted' => 0,
        ]);
    }

    private function createPage(int $uid, string $title): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'slug' => '/',
            'doktype' => 1,
            'hidden' => 0,
            'deleted' => 0,
            'sorting' => 256,
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_groupid' => 0,
            'perms_group' => 0,
            'perms_everybody' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
    }

    private function countWorkspaces(): int
    {
        return (int)$this->connectionPool->getConnectionForTable('sys_workspace')->count(
            '*',
            'sys_workspace',
            ['deleted' => 0],
        );
    }
}
