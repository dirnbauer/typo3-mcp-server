<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\AuthenticationRateLimiter;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Covers the sessionTimeout extension setting.
 *
 * Sessions only exist for clients on the pre-2026-07-28 lifecycle. For those
 * the timeout decides when a connection that is still in use dies: the server
 * answers the next request with 404 (spec 5.8.4) and the client has to run a
 * fresh initialize - if it handles that at all. The tests age the stored
 * session instead of waiting, which is what an editor pausing their work
 * does to it.
 */
class McpEndpointSessionTimeoutTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;
    private mixed $previousExtensionConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousExtensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        if ($this->previousExtensionConfiguration === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionConfiguration;
        }
        parent::tearDown();
    }

    public function testSessionOutlivesAnIdleHourWithTheDefaultTimeout(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout']);

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        // An hour of thinking, reading or meetings: expired under the former
        // hard-coded 1800 seconds, still well inside the 14400 second default.
        $this->ageSession($sessionId, 3600);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $this->assertToolsListSucceeded($response);
    }

    public function testSessionExpiresAfterTheConfiguredTimeout(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout'] = '60';

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        $this->ageSession($sessionId, 120);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonPositiveTimeoutFallsBackToTheDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['sessionTimeout'] = '0';

        $token = $this->createAccessToken();
        $sessionId = $this->initializeSession($token);

        $this->ageSession($sessionId, 3600);

        $response = $this->dispatch(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        $this->assertToolsListSucceeded($response);
    }

    /**
     * A session that is still alive answers the call - and answers it
     * properly: a JSON-RPC error would come back as 200 as well.
     */
    private function assertToolsListSucceeded(ResponseInterface $response): void
    {
        $raw = (string)$response->getBody();
        self::assertSame(200, $response->getStatusCode(), $raw);

        $body = json_decode($raw, true);
        self::assertIsArray($body, $raw);
        self::assertArrayNotHasKey('error', $body, $raw);
        self::assertNotEmpty($body['result']['tools'] ?? [], $raw);
    }

    /**
     * Run the classic handshake of a session-based client and return the
     * session id the server handed out.
     */
    private function initializeSession(string $token): string
    {
        $response = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'session-timeout-test', 'version' => '1.0'],
            ],
        ], $token);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $sessionId = $response->getHeaderLine('mcp-session-id');
        self::assertNotSame('', $sessionId, 'initialize must hand out a session id');

        $this->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $token,
            ['Mcp-Session-Id' => $sessionId]
        );

        return $sessionId;
    }

    /**
     * Backdate the stored session so it looks idle for the given time.
     */
    private function ageSession(string $sessionId, int $seconds): void
    {
        $path = Environment::getVarPath() . '/mcp_sessions/session-' . $sessionId . '.json';
        self::assertFileExists($path);

        $data = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($data, 'Session file must contain a JSON object');
        $data['last_activity'] -= $seconds;
        file_put_contents($path, json_encode($data));
    }

    private function createAccessToken(): string
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        return $oauthService->createDirectAccessToken(1, 'session-timeout-test', new ServerRequest('https://example.com/mcp'));
    }

    /**
     * Dispatch a JSON-RPC message to the endpoint the way the middleware
     * would: as a PSR-7 request.
     */
    private function dispatch(array $jsonRpc, string $token, array $extraHeaders = []): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode($jsonRpc));
        $body->rewind();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            $body,
            array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $extraHeaders)
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return ($this->createEndpoint())($request);
    }
    private function createEndpoint(): McpEndpoint
    {
        $container = $this->getContainer();
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(McpEndpoint::class);

        return new McpEndpoint(
            $logger,
            $container->get(OAuthService::class),
            new BackendUserContextService(
                $container->get(ConnectionPool::class),
                GeneralUtility::makeInstance(Context::class),
                $container->get(WorkspaceContextService::class),
                $container->get(LanguageServiceFactory::class),
            ),
            new ExtensionConfiguration(),
            new SiteBaseUrlResolver(),
            authenticationRateLimiter: new AuthenticationRateLimiter(
                $this->getContainer()->get(RateLimiterFactory::class),
                new NullLogger(),
            ),
        );
    }
}
