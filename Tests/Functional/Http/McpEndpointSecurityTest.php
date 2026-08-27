<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Server\Transport\Http\HttpMessage;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
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
 * HTTP entry hardening: CORS, auth diagnostics, and header-only bearer tokens.
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
            new SiteBaseUrlResolver(),
        );
    }

    #[Test]
    public function testOptionsPreflightReturns200WithCorsHeaders(): void
    {
        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('OPTIONS', 'https://example.org/mcp')
            ->withHeader('Origin', 'https://example.org')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader(
                'Access-Control-Request-Headers',
                'authorization, mcp-session-id, mcp-method, mcp-name, mcp-param-region',
            );

        $GLOBALS['TYPO3_REQUEST'] = $request;
        $response = $endpoint($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertStringContainsString(
            'Mcp-Session-Id',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
        self::assertStringContainsString(
            'Mcp-Method',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
        self::assertStringContainsString(
            'Mcp-Param-region',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function testCrossOriginMcpRequestIsRejectedBeforeAuthentication(): void
    {
        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withHeader('Origin', 'https://untrusted-client.example')
            ->withHeader('Authorization', 'Bearer not-a-real-token');

        $response = $endpoint($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertSame('Forbidden', $json['error'] ?? null);
    }

    #[Test]
    public function testSdkOriginGuardReceivesRequestAndConfiguredHostnames(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['allowedOrigins' => 'https://trusted-client.example:8443, https://SECOND.example'],
        );
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp');
        $endpoint = $this->createEndpoint();
        $method = new \ReflectionMethod($endpoint, 'getAllowedCorsHostnames');

        $hostnames = $method->invoke($endpoint, $request);

        self::assertSame(
            ['example.org', 'trusted-client.example', 'second.example'],
            $hostnames,
        );
    }

    #[Test]
    public function testSdkRequestAdapterUsesThePsrRequestInsteadOfPhpGlobals(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp?region=eu')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Method', 'tools/list')
            ->withQueryParams(['region' => 'eu', 'page' => 2, 'nested' => ['ignored']]);
        $body = new Stream('php://temp', 'rw');
        $body->write('{"jsonrpc":"2.0"}');
        $body->rewind();
        $request = $request->withBody($body);

        $endpoint = $this->createEndpoint();
        $method = new \ReflectionMethod($endpoint, 'createSdkHttpRequest');
        $adapted = $method->invoke($endpoint, $request);

        self::assertInstanceOf(HttpMessage::class, $adapted);
        self::assertSame('POST', $adapted->getMethod());
        self::assertSame('/mcp?region=eu', $adapted->getUri());
        self::assertSame('{"jsonrpc":"2.0"}', $adapted->getBody());
        self::assertSame('tools/list', $adapted->getHeader('Mcp-Method'));
        self::assertSame(['region' => 'eu', 'page' => '2'], $adapted->getQueryParams());
    }

    #[Test]
    public function testSdkRequestAdapterRejectsOversizedContentLengthBeforeReadingBody(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withHeader('Content-Length', (string)(25 * 1024 * 1024 + 1));
        $endpoint = $this->createEndpoint();
        $method = new \ReflectionMethod($endpoint, 'createSdkHttpRequest');

        $this->expectException(\LengthException::class);
        $method->invoke($endpoint, $request);
    }

    #[Test]
    public function testSdkLowercaseContentTypeHeaderIsPreservedWithoutDuplicate(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp');
        $oauthService = $this->getContainer()->get(OAuthService::class);
        assert($oauthService instanceof OAuthService);
        $token = $oauthService->createDirectAccessToken(1, 'content-type-test', $request);

        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'content-type-test', 'version' => '1.0'],
            ],
        ], JSON_THROW_ON_ERROR));
        $body->rewind();
        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $contentTypeHeaders = array_values(array_filter(
            array_keys($response->getHeaders()),
            static fn(string $name): bool => strtolower($name) === 'content-type',
        ));
        self::assertSame(['content-type'], $contentTypeHeaders);
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
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
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('error', $json);
        self::assertSame('forbidden', $json['error']);
    }

    #[Test]
    public function testAuthHeaderDiagnosticFailsClosedWhenSettingIsMissing(): void
    {
        $settings = $this->originalMcpExtensionSettings;
        unset($settings['enableMcpAuthHeaderDiagnostic']);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $settings;

        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth']);

        $response = $endpoint($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
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
    public function testQueryTokenIsAlwaysIgnored(): void
    {
        $endpoint = $this->createEndpoint();
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withQueryParams(['token' => 'not-a-real-token']);

        $response = $endpoint($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function testAuthenticationMetadataPreservesHttpDefaultPortOnHttpsUri(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org:80/mcp');

        $response = ($this->createEndpoint())($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata="https://example.org:80/.well-known/oauth-protected-resource/mcp"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    #[Test]
    public function testAuthenticationMetadataPreservesHttpsDefaultPortOnHttpUri(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'http://example.org:443/mcp');

        $response = ($this->createEndpoint())($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata="http://example.org:443/.well-known/oauth-protected-resource/mcp"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    #[Test]
    public function testAuthenticationMetadataPreservesBracketedIpv6Authority(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://[2001:db8::1]:8443/mcp');

        $response = ($this->createEndpoint())($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata="https://[2001:db8::1]:8443/.well-known/oauth-protected-resource/mcp"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    #[Test]
    public function testAuthenticationMetadataBracketsIpv6HostFromPsrUri(): void
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('2001:db8::1');
        $uri->method('getPort')->willReturn(8443);

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory
            ->createServerRequest('POST', 'https://example.org/mcp')
            ->withUri($uri);

        $response = ($this->createEndpoint())($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata="https://[2001:db8::1]:8443/.well-known/oauth-protected-resource/mcp"',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }

    #[Test]
    public function testAuthenticationMetadataOmitsSchemeSpecificDefaultPorts(): void
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);

        foreach ([
            'http://example.org:80/mcp' => 'http://example.org',
            'https://example.org:443/mcp' => 'https://example.org',
        ] as $requestUri => $expectedBaseUrl) {
            $request = $factory->createServerRequest('POST', $requestUri);
            $response = ($this->createEndpoint())($request);

            self::assertSame(401, $response->getStatusCode());
            self::assertStringContainsString(
                'resource_metadata="' . $expectedBaseUrl . '/.well-known/oauth-protected-resource/mcp"',
                $response->getHeaderLine('WWW-Authenticate'),
            );
        }
    }

    #[Test]
    public function testMcpRouteAcceptsTrailingSlashWithoutFallingThroughToHtmlFrontend(): void
    {
        $middleware = $this->getContainer()->get(McpServerMiddleware::class);
        self::assertInstanceOf(McpServerMiddleware::class, $middleware);

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp/');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
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
    public function testRemovedQueryTokenSettingCannotReEnableUriBearerTokens(): void
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
        self::assertSame('Missing authentication token', $json['message'] ?? null);
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
        self::assertTrue($method->invoke($endpoint, 1));

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

    #[Test]
    public function testBearerTokenStopsWorkingAsSoonAsBackendUserIsDisabled(): void
    {
        $oauthService = $this->getContainer()->get(OAuthService::class);
        self::assertInstanceOf(OAuthService::class, $oauthService);
        $token = $oauthService->createDirectAccessToken(
            1,
            'disabled-user-test',
            resource: 'https://example.org/mcp',
        );

        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool
            ->getConnectionForTable('be_users')
            ->update('be_users', ['disable' => 1], ['uid' => 1]);

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withHeader('Authorization', 'Bearer ' . $token);

        $response = ($this->createEndpoint())($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function testReadOnlyBackendUserCanInitializeInLiveReadContextWithoutWritableWorkspace(): void
    {
        $connectionPool = $this->getContainer()->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 2,
            'pid' => 0,
            'username' => 'mcp_read_only',
            'password' => '',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'workspace_id' => 0,
            'workspace_perms' => 0,
            'userMods' => '',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $endpoint = $this->createEndpoint();
        $method = new \ReflectionMethod($endpoint, 'setupBackendUserContext');

        self::assertTrue($method->invoke($endpoint, 2));
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        self::assertSame(0, $backendUser->workspace);
    }
}
