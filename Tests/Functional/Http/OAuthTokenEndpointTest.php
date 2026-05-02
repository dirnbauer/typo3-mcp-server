<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class OAuthTokenEndpointTest extends AbstractFunctionalTest
{
    private OAuthService $oauthService;

    protected function setUp(): void
    {
        parent::setUp();

        $service = $this->getContainer()->get(OAuthService::class);
        assert($service instanceof OAuthService);
        $this->oauthService = $service;
    }

    public function testAuthorizationCodeGrantAcceptsJsonBody(): void
    {
        $code = $this->oauthService->createAuthorizationCode(1, 'Cursor');
        $response = ($this->createEndpoint())($this->createJsonTokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => 'typo3-mcp-server',
            'code' => $code,
        ]));

        $payload = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('access_token', $payload);
        self::assertArrayHasKey('refresh_token', $payload);
        self::assertSame('Bearer', $payload['token_type'] ?? null);
    }

    public function testRefreshTokenGrantAcceptsFormBody(): void
    {
        $code = $this->oauthService->createAuthorizationCode(1, 'Cursor');
        $firstTokenPair = $this->oauthService->exchangeCodeForToken($code);
        self::assertIsArray($firstTokenPair);

        $response = ($this->createEndpoint())($this->createFormTokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => 'typo3-mcp-server',
            'refresh_token' => $firstTokenPair['refresh_token'],
        ]));

        $payload = $this->decodeJsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('access_token', $payload);
        self::assertArrayHasKey('refresh_token', $payload);
        self::assertNotSame($firstTokenPair['access_token'], $payload['access_token'] ?? null);
        self::assertNotSame($firstTokenPair['refresh_token'], $payload['refresh_token'] ?? null);
    }

    private function createEndpoint(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint(
            GeneralUtility::makeInstance(LogManager::class)->getLogger(OAuthTokenEndpoint::class),
            $this->oauthService,
        );
    }

    /**
     * @param array<string, string> $payload
     */
    private function createJsonTokenRequest(array $payload): ServerRequest
    {
        return $this->createTokenRequest(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json');
    }

    /**
     * @param array<string, string> $payload
     */
    private function createFormTokenRequest(array $payload): ServerRequest
    {
        return $this->createTokenRequest(http_build_query($payload), 'application/x-www-form-urlencoded');
    }

    private function createTokenRequest(string $body, string $contentType): ServerRequest
    {
        $resource = fopen('php://temp', 'rw');
        self::assertIsResource($resource);
        fwrite($resource, $body);
        rewind($resource);

        return new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'POST',
            $resource,
            ['Content-Type' => $contentType],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
