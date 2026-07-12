<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\AbilityBackendUserContextService;
use Hn\McpServer\Service\McpCliBackendUserBootstrapService;
use Hn\McpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class McpCliBackendUserBootstrapServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private ConnectionPool $connectionPool;
    private McpCliBackendUserBootstrapService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $abilityContext = new AbilityBackendUserContextService(
            $this->connectionPool,
            GeneralUtility::makeInstance(Context::class),
            GeneralUtility::makeInstance(WorkspaceContextService::class),
            GeneralUtility::makeInstance(LanguageServiceFactory::class),
        );
        $this->subject = new McpCliBackendUserBootstrapService($abilityContext);
    }

    #[Test]
    public function uidlessSyntheticUserIsReplacedWithAuthenticatedCliUserAndSession(): void
    {
        $syntheticUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $syntheticUser->user = ['uid' => 0, 'admin' => 1, 'username' => 'synthetic'];
        $GLOBALS['BE_USER'] = $syntheticUser;

        $backendUser = $this->subject->initialize();

        self::assertInstanceOf(CommandLineUserAuthentication::class, $backendUser);
        self::assertNotSame($syntheticUser, $backendUser);
        self::assertGreaterThan(0, (int)($backendUser->user['uid'] ?? 0));
        self::assertSame('_cli_', $backendUser->user['username'] ?? null);
        $backendUser->setAndSaveSessionData('mcp-cli-test', 'ready');
        self::assertSame('ready', $backendUser->getSessionData('mcp-cli-test'));

        $context = GeneralUtility::makeInstance(Context::class);
        $backendAspect = $context->getAspect('backend.user');
        self::assertInstanceOf(UserAspect::class, $backendAspect);
        self::assertSame((int)$backendUser->user['uid'], $backendAspect->get('id'));
        $workspaceAspect = $context->getAspect('workspace');
        self::assertInstanceOf(WorkspaceAspect::class, $workspaceAspect);
        self::assertSame(0, $workspaceAspect->getId());
    }

    #[Test]
    public function activeDatabaseBackedUserIsPreservedAndHydrated(): void
    {
        $this->createBackendUser(2, false);
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid(2);
        self::assertIsArray($backendUser->user);
        $GLOBALS['BE_USER'] = $backendUser;

        $initialized = $this->subject->initialize();

        self::assertSame($backendUser, $initialized);
        self::assertSame(2, (int)($initialized->user['uid'] ?? 0));
        $initialized->setSessionData('mcp-cli-active-user', 'ready');
        self::assertSame('ready', $initialized->getSessionData('mcp-cli-active-user'));
    }

    #[Test]
    public function inactiveDatabaseBackedUserFailsClosed(): void
    {
        $this->createBackendUser(2, true);
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 2, 'username' => 'disabled_cli_user'];
        $GLOBALS['BE_USER'] = $backendUser;

        $this->expectException(AccessDeniedException::class);

        $this->subject->initialize();
    }

    private function createBackendUser(int $uid, bool $disabled): void
    {
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'pid' => 0,
            'username' => 'cli_user_' . $uid,
            'password' => '',
            'admin' => 1,
            'disable' => $disabled ? 1 : 0,
            'deleted' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'workspace_id' => 0,
            'workspace_perms' => 1,
            'uc' => '',
            'lang' => 'default',
            'tstamp' => time(),
            'crdate' => time(),
        ]);
    }
}
