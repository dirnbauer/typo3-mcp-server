<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Raw authenticated HTTP checks for the stable and stateless MCP wire eras.
 */
final class McpEndpointProtocolTest extends FunctionalTestCase
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

    private string $accessToken = '';

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

    #[Test]
    public function legacyHttpLifecycleKeepsSessionAndStripsModernResultFields(): void
    {
        $initialize = $this->sendJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'functional-http-legacy', 'version' => '1.0'],
            ],
        ]);

        self::assertSame(200, $initialize->getStatusCode(), (string)$initialize->getBody());
        $sessionId = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $sessionId);
        $initializeBody = $this->decodeResponse($initialize);
        self::assertSame('2025-11-25', $initializeBody['result']['protocolVersion'] ?? null);

        $initialized = $this->sendJsonRpc([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => new \stdClass(),
        ], [
            'Mcp-Session-Id' => $sessionId,
            'MCP-Protocol-Version' => '2025-11-25',
        ]);
        self::assertContains($initialized->getStatusCode(), [200, 202]);

        $tools = $this->sendJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ], [
            'Mcp-Session-Id' => $sessionId,
            'MCP-Protocol-Version' => '2025-11-25',
        ]);

        self::assertSame(200, $tools->getStatusCode(), (string)$tools->getBody());
        self::assertSame($sessionId, $tools->getHeaderLine('Mcp-Session-Id'));
        $result = $this->decodeResponse($tools)['result'] ?? null;
        self::assertIsArray($result);
        self::assertNotEmpty($result['tools'] ?? []);
        self::assertArrayNotHasKey('resultType', $result);
        self::assertArrayNotHasKey('ttlMs', $result);
        self::assertArrayNotHasKey('cacheScope', $result);
    }

    #[Test]
    public function modernHttpDiscoveryAndCatalogAreStatelessAndCacheable(): void
    {
        $discover = $this->sendModern('server/discover', [], id: 10);
        self::assertSame(200, $discover->getStatusCode(), (string)$discover->getBody());
        self::assertSame('', $discover->getHeaderLine('Mcp-Session-Id'));
        $discoverResult = $this->decodeResponse($discover)['result'] ?? null;
        self::assertIsArray($discoverResult);
        self::assertContains('2026-07-28', $discoverResult['supportedVersions'] ?? []);
        self::assertSame('complete', $discoverResult['resultType'] ?? null);

        $tools = $this->sendModern('tools/list', [], id: 11);
        self::assertSame(200, $tools->getStatusCode(), (string)$tools->getBody());
        self::assertSame('', $tools->getHeaderLine('Mcp-Session-Id'));
        $toolResult = $this->decodeResponse($tools)['result'] ?? null;
        self::assertIsArray($toolResult);
        self::assertNotEmpty($toolResult['tools'] ?? []);
        self::assertSame('complete', $toolResult['resultType'] ?? null);
        self::assertSame(30_000, $toolResult['ttlMs'] ?? null);
        self::assertSame('private', $toolResult['cacheScope'] ?? null);

        $resource = $this->sendModern(
            'resources/read',
            ['uri' => 'typo3-mcp:///skills'],
            id: 12,
            name: 'typo3-mcp:///skills',
        );
        self::assertSame(200, $resource->getStatusCode(), (string)$resource->getBody());
        $resourceResult = $this->decodeResponse($resource)['result'] ?? null;
        self::assertIsArray($resourceResult);
        self::assertSame('complete', $resourceResult['resultType'] ?? null);
        self::assertSame(60_000, $resourceResult['ttlMs'] ?? null);
        self::assertSame('private', $resourceResult['cacheScope'] ?? null);
    }

    #[Test]
    public function modernHttpToolResultRetainsTextAndAddsStructuredContent(): void
    {
        $response = $this->sendModern(
            'tools/call',
            ['name' => 'GetCapabilities', 'arguments' => new \stdClass()],
            id: 20,
            name: 'GetCapabilities',
        );

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('', $response->getHeaderLine('Mcp-Session-Id'));
        $result = $this->decodeResponse($response)['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame('complete', $result['resultType'] ?? null);
        self::assertNotEmpty($result['content'] ?? []);
        self::assertIsArray($result['structuredContent'] ?? null);
    }

    #[Test]
    public function modernHttpRejectsRoutingMismatchAndUsesNegotiatedUnknownErrors(): void
    {
        $mismatch = $this->sendModern('tools/list', [], id: 30, methodHeader: 'prompts/list');
        self::assertSame(400, $mismatch->getStatusCode(), (string)$mismatch->getBody());
        self::assertSame(-32020, $this->decodeResponse($mismatch)['error']['code'] ?? null);

        $unknownTool = $this->sendModern(
            'tools/call',
            ['name' => 'DefinitelyMissingTool', 'arguments' => new \stdClass()],
            id: 31,
            name: 'DefinitelyMissingTool',
        );
        self::assertSame(-32602, $this->decodeResponse($unknownTool)['error']['code'] ?? null);

        $unknownResource = $this->sendModern(
            'resources/read',
            ['uri' => 'typo3-mcp:///skills/missing'],
            id: 32,
            name: 'typo3-mcp:///skills/missing',
        );
        $unknownResourceBody = $this->decodeResponse($unknownResource);
        self::assertSame(
            -32602,
            $unknownResourceBody['error']['code'] ?? null,
            (string)json_encode($unknownResourceBody, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sendModern(
        string $method,
        array $params,
        int $id,
        ?string $name = null,
        ?string $methodHeader = null,
    ): ResponseInterface {
        $params['_meta'] = $this->modernEnvelope();
        $headers = [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $methodHeader ?? $method,
        ];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }

        return $this->sendJsonRpc([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $headers);
    }

    /** @return array<string, mixed> */
    private function modernEnvelope(): array
    {
        return [
            MetaKeys::PROTOCOL_VERSION => '2026-07-28',
            MetaKeys::CLIENT_INFO => ['name' => 'functional-http-modern', 'version' => '1.0'],
            MetaKeys::CLIENT_CAPABILITIES => [],
        ];
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function sendJsonRpc(array $payload, array $headers = []): ResponseInterface
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($this->accessToken === '') {
            $oauthService = $this->getContainer()->get(OAuthService::class);
            self::assertInstanceOf(OAuthService::class, $oauthService);
            $this->accessToken = $oauthService->createDirectAccessToken(1, 'functional-http-protocol', $request);
        }

        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode($payload, JSON_THROW_ON_ERROR));
        $body->rewind();
        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return ($this->createEndpoint())($request);
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function createEndpoint(): McpEndpoint
    {
        $container = $this->getContainer();
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(McpEndpoint::class);
        assert($logger instanceof LoggerInterface);

        return new McpEndpoint(
            $logger,
            $container->get(OAuthService::class),
            $container->get(ConnectionPool::class),
            $container->get(WorkspaceContextService::class),
            $container->get(LanguageServiceFactory::class),
            new ExtensionConfiguration(),
        );
    }
}
