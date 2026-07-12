<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\OAuthService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class OAuthServiceTest extends TestCase
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $connectionPool = self::createStub(ConnectionPool::class);
        $this->service = new OAuthService($connectionPool);
    }

    public function testGenerateAuthorizationUrlContainsClientId(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            codeChallenge: $this->validChallenge(),
        );

        self::assertStringContainsString('client_id=typo3-mcp-server', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('resource=https%3A%2F%2Fexample.com%2Fmcp', $url);
        self::assertStringContainsString('scope=mcp_access', $url);
    }

    public function testGenerateAuthorizationUrlRejectsRedirectForBuiltInClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept redirect_uri');

        $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            'https://callback.example.com/oauth',
            $this->validChallenge(),
        );
    }

    public function testGenerateAuthorizationUrlContainsCodeChallenge(): void
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            '',
            $challenge,
            'S256',
        );

        self::assertStringContainsString('code_challenge=' . $challenge, $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testGenerateAuthorizationUrlRejectsMalformedPkceChallenge(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->generateAuthorizationUrl(
            'https://example.com',
            codeChallenge: 'too-short',
        );
    }

    public function testGenerateAuthorizationUrlContainsState(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            '',
            $this->validChallenge(),
            'S256',
            'random-state-value',
        );

        self::assertStringContainsString('state=random-state-value', $url);
    }

    public function testGenerateAuthorizationUrlStripsTrailingSlash(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com/',
            codeChallenge: $this->validChallenge(),
        );

        self::assertStringStartsWith('https://example.com/mcp_oauth/authorize?', $url);
    }

    public function testGenerateAuthorizationUrlContainsClientName(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'My Custom Client',
            codeChallenge: $this->validChallenge(),
        );

        self::assertStringContainsString('client_name=My+Custom+Client', $url);
    }

    public function testGenerateAuthorizationUrlOmitsRedirectAndState(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            codeChallenge: $this->validChallenge(),
        );

        self::assertStringNotContainsString('redirect_uri=', $url);
        self::assertStringNotContainsString('state=', $url);
        self::assertStringContainsString('code_challenge=', $url);
    }

    public function testGetMetadataContainsExpectedEndpoints(): void
    {
        $metadata = $this->service->getMetadata('https://example.com/');

        self::assertSame('https://example.com', $metadata['issuer']);
        self::assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
        self::assertSame('https://example.com/mcp_oauth/register', $metadata['registration_endpoint']);
        self::assertSame(['authorization_code', 'refresh_token'], $metadata['grant_types_supported']);
        self::assertTrue($metadata['authorization_response_iss_parameter_supported']);
    }

    public function testRegisterClientReturnsSupportedGrantTypes(): void
    {
        $client = $this->service->registerClient([
            'client_name' => 'Cursor',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'redirect_uris' => ['http://127.0.0.1/callback'],
        ]);

        self::assertSame(['authorization_code', 'refresh_token'], $client['grant_types']);
    }

    public function testRegisterClientRejectsUnsupportedGrantTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('grant_types contains an unsupported value');

        $this->service->registerClient([
            'client_name' => 'Cursor',
            'grant_types' => ['client_credentials'],
            'response_types' => ['code'],
            'redirect_uris' => ['http://127.0.0.1/callback'],
        ]);
    }

    private function validChallenge(): string
    {
        $verifier = str_repeat('p', 64);
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
