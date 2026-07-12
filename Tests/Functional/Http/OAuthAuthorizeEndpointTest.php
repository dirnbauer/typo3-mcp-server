<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class OAuthAuthorizeEndpointTest extends AbstractFunctionalTest
{
    private OAuthService $oauthService;
    private OAuthAuthorizeEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauthService = $this->getService(OAuthService::class);
        $this->endpoint = new OAuthAuthorizeEndpoint(
            $this->oauthService,
            GeneralUtility::makeInstance(HashService::class),
            GeneralUtility::makeInstance(FormProtectionFactory::class),
            GeneralUtility::makeInstance(SiteBaseUrlResolver::class),
        );
    }

    public function testRegisteredHttpsRedirectCompletesBoundAuthorizationCodeFlow(): void
    {
        $client = $this->oauthService->registerClient([
            'client_name' => 'Remote editor',
            'redirect_uris' => ['https://client.example/oauth/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);
        $verifier = str_repeat('e', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [
            'client_id' => $client['client_id'],
            'response_type' => 'code',
            'redirect_uri' => 'https://client.example/oauth/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://cms.example/mcp',
            'scope' => OAuthService::DEFAULT_SCOPE,
            'state' => 'opaque-client-state',
        ];
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);

        $consentResponse = ($this->endpoint)(
            $factory->createServerRequest('GET', 'https://cms.example/mcp_oauth/authorize')
                ->withQueryParams($query),
        );

        self::assertSame(200, $consentResponse->getStatusCode());
        $consentHtml = (string)$consentResponse->getBody();
        self::assertStringContainsString('Remote editor', $consentHtml);
        $formToken = $this->extractFormToken($consentHtml);

        $approvalResponse = ($this->endpoint)(
            $factory->createServerRequest('POST', 'https://cms.example/mcp_oauth/authorize')
                ->withQueryParams($query)
                ->withParsedBody([
                    'approve' => '1',
                    'state' => 'opaque-client-state',
                    'form_token' => $formToken,
                ]),
        );

        self::assertSame(302, $approvalResponse->getStatusCode());
        $location = $approvalResponse->getHeaderLine('Location');
        self::assertStringStartsWith('https://client.example/oauth/callback?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $redirectQuery);
        self::assertSame('opaque-client-state', $redirectQuery['state'] ?? null);
        self::assertSame('https://cms.example', $redirectQuery['iss'] ?? null);
        self::assertIsString($redirectQuery['code'] ?? null);

        $tokens = $this->oauthService->exchangeCodeForToken(
            $redirectQuery['code'],
            $verifier,
            clientId: $client['client_id'],
            redirectUri: 'https://client.example/oauth/callback',
            resource: 'https://cms.example/mcp',
        );
        self::assertIsArray($tokens);
    }

    public function testMalformedPkceChallengeIsRejectedBeforeConsent(): void
    {
        $client = $this->oauthService->registerClient([
            'client_name' => 'Remote editor',
            'redirect_uris' => ['https://client.example/oauth/callback'],
        ]);
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://cms.example/mcp_oauth/authorize')
            ->withQueryParams([
                'client_id' => $client['client_id'],
                'response_type' => 'code',
                'redirect_uri' => 'https://client.example/oauth/callback',
                'code_challenge' => 'too-short',
                'code_challenge_method' => 'S256',
                'resource' => 'https://cms.example/mcp',
                'scope' => OAuthService::DEFAULT_SCOPE,
            ]);

        $response = ($this->endpoint)($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('code_challenge', (string)$response->getBody());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://cms.example', $payload['iss'] ?? null);
    }

    public function testApprovalRejectsMissingConsentToken(): void
    {
        [$query, $factory] = $this->createValidAuthorizationRequest();

        $response = ($this->endpoint)(
            $factory->createServerRequest('POST', 'https://cms.example/mcp_oauth/authorize')
                ->withQueryParams($query)
                ->withParsedBody(['approve' => '1']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('invalid or expired', (string)$response->getBody());
    }

    public function testApprovalRejectsInvalidConsentToken(): void
    {
        [$query, $factory] = $this->createValidAuthorizationRequest();

        $response = ($this->endpoint)(
            $factory->createServerRequest('POST', 'https://cms.example/mcp_oauth/authorize')
                ->withQueryParams($query)
                ->withParsedBody(['approve' => '1', 'form_token' => 'forged-token']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('invalid or expired', (string)$response->getBody());
    }

    public function testInsecureNonLoopbackIssuerReturnsBoundedErrorWithoutIssuerClaim(): void
    {
        $client = $this->oauthService->registerClient([
            'client_name' => 'Insecure issuer test',
            'redirect_uris' => ['https://client.example/oauth/callback'],
        ]);
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $response = ($this->endpoint)(
            $factory->createServerRequest('GET', 'http://cms.example/mcp_oauth/authorize')
                ->withQueryParams([
                    'client_id' => $client['client_id'],
                    'response_type' => 'code',
                    'redirect_uri' => 'https://client.example/oauth/callback',
                    'code_challenge' => 'too-short',
                    'code_challenge_method' => 'S256',
                    'resource' => 'http://cms.example/mcp',
                    'scope' => OAuthService::DEFAULT_SCOPE,
                ]),
        );

        self::assertSame(400, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_request', $payload['error'] ?? null);
        self::assertArrayNotHasKey('iss', $payload);
    }

    public function testSignedLoginContinuationCookieHasServerSideExpiry(): void
    {
        $middleware = $this->getService(McpServerMiddleware::class);
        $decode = new \ReflectionMethod($middleware, 'decodeOAuthCookie');

        $fresh = $decode->invoke($middleware, $this->signedCookie(['issued_at' => (string)time(), 'state' => 'ok']));
        self::assertSame(['state' => 'ok'], $fresh);

        $expired = $decode->invoke($middleware, $this->signedCookie([
            'issued_at' => (string)(time() - 601),
            'state' => 'expired',
        ]));
        self::assertNull($expired);
        self::assertNull($decode->invoke($middleware, $this->signedCookie(['state' => 'missing-time'])));
    }

    /** @param array<string, string> $data */
    private function signedCookie(array $data): string
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $signature = GeneralUtility::makeInstance(HashService::class)
            ->hmac($payload, 'mcpserver-oauth', HashAlgo::SHA3_256);

        return base64_encode($payload) . '.' . $signature;
    }

    /** @return array{array<string, string>, ServerRequestFactory} */
    private function createValidAuthorizationRequest(): array
    {
        $client = $this->oauthService->registerClient([
            'client_name' => 'CSRF test client',
            'redirect_uris' => ['https://client.example/oauth/callback'],
        ]);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [[
            'client_id' => $client['client_id'],
            'response_type' => 'code',
            'redirect_uri' => 'https://client.example/oauth/callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://cms.example/mcp',
            'scope' => OAuthService::DEFAULT_SCOPE,
        ], GeneralUtility::makeInstance(ServerRequestFactory::class)];
    }

    private function extractFormToken(string $html): string
    {
        self::assertSame(1, preg_match('/name="form_token" value="([^"]+)"/', $html, $matches));
        self::assertArrayHasKey(1, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
