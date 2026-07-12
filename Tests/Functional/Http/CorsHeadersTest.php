<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class CorsHeadersTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    /** @var array<string, mixed> */
    private array $previousExtensionConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousExtensionConfiguration = is_array($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']
            : [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionConfiguration;
        parent::tearDown();
    }

    private function createEndpoint(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint(
            GeneralUtility::makeInstance(LogManager::class)->getLogger(OAuthTokenEndpoint::class),
            $this->getContainer()->get(OAuthService::class),
        );
    }

    public function testSameOriginCredentialedCorsIsAllowed(): void
    {
        $request = $this->preflightRequest('https://example.com');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testCrossOriginRequestIsDeniedByDefault(): void
    {
        $request = $this->preflightRequest('https://untrusted-client.example');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testMalformedOriginSerializationIsDenied(): void
    {
        $request = $this->preflightRequest('https://example.com/');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testConfiguredExactCrossOriginIsAllowed(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->previousExtensionConfiguration,
            ['allowedOrigins' => 'https://trusted-client.example, https://second-client.example:8443/'],
        );
        $request = $this->preflightRequest('https://trusted-client.example');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://trusted-client.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsWithoutOriginHeaderSkipsCorsHeaders(): void
    {
        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS',
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testModernMcpRoutingAndValidatedParameterHeadersAreAllowed(): void
    {
        $request = $this->preflightRequest('https://example.com')
            ->withHeader(
                'Access-Control-Request-Headers',
                'authorization, mcp-protocol-version, mcp-method, mcp-name, mcp-param-region',
            );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(200, $response->getStatusCode());
        $allowedHeaders = strtolower($response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertStringContainsString('mcp-method', $allowedHeaders);
        self::assertStringContainsString('mcp-name', $allowedHeaders);
        self::assertStringContainsString('mcp-param-region', $allowedHeaders);
        self::assertStringContainsString(
            'mcp-param-region',
            strtolower($response->getHeaderLine('Access-Control-Expose-Headers')),
        );
    }

    public function testUnknownRequestedCorsHeaderIsDenied(): void
    {
        $request = $this->preflightRequest('https://example.com')
            ->withHeader('Access-Control-Request-Headers', 'authorization, x-forwarded-host');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = ($this->createEndpoint())($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function testProtectedResourceMetadataAdvertisesHeaderBearerOnly(): void
    {
        $endpoint = new OAuthResourceMetadataEndpoint(new SiteBaseUrlResolver());
        $request = new ServerRequest(
            new Uri('https://example.com/.well-known/oauth-protected-resource/mcp'),
            'GET',
            'php://input',
            ['Origin' => 'https://example.com'],
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);
        $metadata = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($metadata);
        self::assertSame(['header'], $metadata['bearer_methods_supported'] ?? null);
        self::assertArrayNotHasKey('revocation_endpoint', $metadata);
        self::assertArrayNotHasKey('revocation_endpoint_auth_methods_supported', $metadata);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function preflightRequest(string $origin): ServerRequest
    {
        return new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS',
            'php://input',
            [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
            ],
        );
    }
}
