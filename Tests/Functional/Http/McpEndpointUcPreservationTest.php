<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\AuthenticationRateLimiter;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The token-authenticated /mcp endpoint impersonates a backend user without
 * going through the regular authentication flow. That flow normally restores
 * the user's stored configuration (uc) via unpack_uc(). If the endpoint skips
 * this, $beUser->uc starts out empty and any writeUC() triggered during
 * request processing (e.g. the update signals fired when the MCP workspace is
 * created) overwrites the user's stored backend preferences with a nearly
 * empty array. Afterwards the backend Setup module crashes with
 * 'Undefined array key "titleLen"' because the defaults are only re-applied
 * when uc is completely empty (upstream #107).
 */
final class McpEndpointUcPreservationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        assert($backendUser instanceof BackendUserAuthentication);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    #[Test]
    public function storedUserConfigurationSurvivesMcpRequest(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $connection->update('be_users', ['uc' => serialize(['titleLen' => 77, 'lang' => 'de', 'emailMeAtLogin' => 1])], ['uid' => 1]);

        $response = $this->sendAuthenticatedToolsList();

        // A 401 means authentication failed before the impersonation
        // path under test could run.
        self::assertNotSame(401, $response->getStatusCode(), (string)$response->getBody());

        // The impersonated backend user must carry the stored configuration
        // in memory, exactly like a regularly authenticated user would.
        self::assertSame(77, $GLOBALS['BE_USER']->uc['titleLen'] ?? null, 'The stored uc must be loaded into the impersonated backend user');

        // Simulate any code path that persists the uc during request
        // processing (workspace creation signals, pushModuleData, ...).
        $GLOBALS['BE_USER']->writeUC();

        $persistedUc = unserialize((string)$connection->select(['uc'], 'be_users', ['uid' => 1])->fetchOne(), ['allowed_classes' => false]);
        self::assertIsArray($persistedUc);
        self::assertSame(77, $persistedUc['titleLen'] ?? null, 'Persisting the uc during an MCP request must not wipe the stored backend preferences');
        self::assertSame('de', $persistedUc['lang'] ?? null);
    }

    #[Test]
    public function defaultsAreAppliedForUserWithoutStoredConfiguration(): void
    {
        // A user who never logged into the backend has no stored uc yet. Such a
        // user must get the uc defaults applied during impersonation, exactly
        // like initializeBackendLogin() does on a first regular login.
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $connection->update('be_users', ['uc' => ''], ['uid' => 1]);

        $response = $this->sendAuthenticatedToolsList();
        self::assertNotSame(401, $response->getStatusCode(), (string)$response->getBody());

        self::assertArrayHasKey('titleLen', $GLOBALS['BE_USER']->uc, 'A user without stored settings must get the uc defaults applied');

        $GLOBALS['BE_USER']->writeUC();
        $persistedUc = unserialize((string)$connection->select(['uc'], 'be_users', ['uid' => 1])->fetchOne(), ['allowed_classes' => false]);
        self::assertIsArray($persistedUc);
        self::assertArrayHasKey('titleLen', $persistedUc, 'The persisted uc of a first-time user must contain the defaults, not a nearly empty array');
    }

    private function sendAuthenticatedToolsList(): ResponseInterface
    {
        $request = GeneralUtility::makeInstance(ServerRequestFactory::class)
            ->createServerRequest('POST', 'https://example.org/mcp', ['REMOTE_ADDR' => '198.51.100.80']);

        $oauthService = $this->getContainer()->get(OAuthService::class);
        assert($oauthService instanceof OAuthService);
        $accessToken = $oauthService->createDirectAccessToken(1, 'uc-preservation-test', $request);

        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass()]));
        $body->rewind();

        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->createEndpoint()($request);
    }

    private function createEndpoint(): McpEndpoint
    {
        $container = $this->getContainer();
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(McpEndpoint::class);
        assert($logger instanceof LoggerInterface);

        $oauthService = $container->get(OAuthService::class);
        assert($oauthService instanceof OAuthService);
        $connectionPool = $container->get(ConnectionPool::class);
        assert($connectionPool instanceof ConnectionPool);
        $workspaceContextService = $container->get(WorkspaceContextService::class);
        assert($workspaceContextService instanceof WorkspaceContextService);
        $languageServiceFactory = $container->get(LanguageServiceFactory::class);
        assert($languageServiceFactory instanceof LanguageServiceFactory);
        $rateLimiterFactory = $container->get(RateLimiterFactory::class);
        assert($rateLimiterFactory instanceof RateLimiterFactory);

        return new McpEndpoint(
            $logger,
            $oauthService,
            new BackendUserContextService(
                $connectionPool,
                GeneralUtility::makeInstance(Context::class),
                $workspaceContextService,
                $languageServiceFactory,
            ),
            new ExtensionConfiguration(),
            new SiteBaseUrlResolver(),
            new AuthenticationRateLimiter($rateLimiterFactory, new NullLogger()),
        );
    }
}
