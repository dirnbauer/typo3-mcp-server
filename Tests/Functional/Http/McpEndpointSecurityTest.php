<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * HTTP entry hardening: auth diagnostic toggle and query-token default.
 */
final class McpEndpointSecurityTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    /** @var array<string, mixed> */
    private array $originalMcpExtensionSettings = [];

    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        assert($backendUser instanceof BackendUserAuthentication);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->originalMcpExtensionSettings = is_array($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']
            : [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->originalMcpExtensionSettings;
        parent::tearDown();
    }

    private function createEndpoint(): McpEndpoint
    {
        $container = $this->getContainer();
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(McpEndpoint::class);
        assert($logger instanceof LoggerInterface);

        $oauthService = $container->get(OAuthService::class);
        $connectionPool = $container->get(ConnectionPool::class);
        $workspaceContextService = $container->get(WorkspaceContextService::class);
        $languageServiceFactory = $container->get(LanguageServiceFactory::class);
        $extensionConfiguration = new ExtensionConfiguration();

        return new McpEndpoint(
            $logger,
            $oauthService,
            $connectionPool,
            $workspaceContextService,
            $languageServiceFactory,
            $extensionConfiguration,
        );
    }

    #[Test]
    public function testOptionsPreflightReturns200WithCorsHeaders(): void
    {
        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('OPTIONS', 'https://example.org/mcp')
            ->withHeader('Origin', 'https://app.cursor.sh')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'authorization, mcp-session-id');

        $GLOBALS['TYPO3_REQUEST'] = $request;
        $response = $endpoint($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertStringContainsString(
            'Mcp-Session-Id',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
    }

    #[Test]
    public function testAuthHeaderDiagnosticDisabledReturns403(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['enableMcpAuthHeaderDiagnostic' => '0'],
        );

        $endpoint = $this->createEndpoint();

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth']);

        $response = $endpoint($request);

        self::assertSame(403, $response->getStatusCode());
        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('error', $json);
        self::assertSame('forbidden', $json['error']);
    }

    #[Test]
    public function testAuthHeaderDiagnosticOmitsServerSoftwareFingerprint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['enableMcpAuthHeaderDiagnostic' => '1'],
        );

        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth']);

        $response = $endpoint($request);
        self::assertSame(200, $response->getStatusCode());

        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertArrayNotHasKey('server_software', $json);
        self::assertArrayHasKey('auth_header_detected', $json);
        self::assertArrayHasKey('headers_received', $json);
    }

    #[Test]
    public function testAuthHeaderDiagnosticReportsAuthorizationWhenPresent(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['enableMcpAuthHeaderDiagnostic' => '1'],
        );

        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth'])
            ->withHeader('Authorization', 'Bearer test-token');

        $response = $endpoint($request);
        self::assertSame(200, $response->getStatusCode());

        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertTrue((bool)($json['auth_header_detected'] ?? false));
        self::assertTrue((bool)($json['headers_received']['authorization'] ?? false));
    }

    #[Test]
    public function testQueryTokenIsIgnoredWhenExtensionSettingDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['allowMcpTokenInQueryString' => '0'],
        );

        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withQueryParams(['token' => 'not-a-real-token']);

        $response = $endpoint($request);
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function testMcpRouteAcceptsTrailingSlashWithoutFallingThroughToHtmlFrontend(): void
    {
        $middleware = $this->getContainer()->get(McpServerMiddleware::class);
        self::assertInstanceOf(McpServerMiddleware::class, $middleware);

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp/');

        $response = $middleware->process($request, new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $stream = new Stream('php://temp', 'rw');
                $stream->write('<html>fallback</html>');
                $stream->rewind();

                return new Response($stream, 404, ['Content-Type' => 'text/html; charset=utf-8']);
            }
        });

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function testQueryTokenIsProcessedWhenExtensionSettingEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['allowMcpTokenInQueryString' => '1'],
        );

        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withQueryParams(['token' => 'not-a-real-token']);

        $response = $endpoint($request);
        self::assertSame(401, $response->getStatusCode());

        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertSame('Invalid or expired token', $json['message'] ?? null);
    }

    #[Test]
    public function testSetupBackendUserContextHydratesMissingUcDefaults(): void
    {
        $serializedUc = serialize(['lang' => 'de']);
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        assert($connectionPool instanceof ConnectionPool);
        $connectionPool
            ->getConnectionForTable('be_users')
            ->update('be_users', ['uc' => $serializedUc], ['uid' => 1]);

        $endpoint = $this->createEndpoint();
        $method = new \ReflectionMethod($endpoint, 'setupBackendUserContext');
        $method->invoke($endpoint, 1);

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        self::assertSame('de', $backendUser->uc['lang'] ?? null);
        self::assertSame(50, $backendUser->uc['titleLen'] ?? null);
        self::assertSame([], $backendUser->uc['moduleData'] ?? null);

        $storedUc = $connectionPool
            ->getConnectionForTable('be_users')
            ->select(['uc'], 'be_users', ['uid' => 1])
            ->fetchOne();
        self::assertSame($serializedUc, $storedUc);
    }
}
