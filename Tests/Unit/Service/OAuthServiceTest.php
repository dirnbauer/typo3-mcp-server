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
        $connectionPool = $this->createMock(ConnectionPool::class);
        $this->service = new OAuthService($connectionPool);
    }

    public function testGenerateAuthorizationUrlContainsClientId(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com');

        self::assertStringContainsString('client_id=typo3-mcp-server', $url);
        self::assertStringContainsString('response_type=code', $url);
    }

    public function testGenerateAuthorizationUrlContainsRedirectUri(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            'https://callback.example.com/oauth',
        );

        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('callback.example.com', $url);
    }

    public function testGenerateAuthorizationUrlContainsCodeChallenge(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            '',
            'challenge-value-123',
            'S256',
        );

        self::assertStringContainsString('code_challenge=challenge-value-123', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testGenerateAuthorizationUrlContainsState(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            '',
            '',
            'S256',
            'random-state-value',
        );

        self::assertStringContainsString('state=random-state-value', $url);
    }

    public function testGenerateAuthorizationUrlStripsTrailingSlash(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com/');

        self::assertStringStartsWith('https://example.com/mcp_oauth/authorize?', $url);
    }

    public function testGenerateAuthorizationUrlContainsClientName(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'My Custom Client',
        );

        self::assertStringContainsString('client_name=My+Custom+Client', $url);
    }

    public function testGenerateAuthorizationUrlWithEmptyOptionalParams(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com');

        self::assertStringNotContainsString('redirect_uri=', $url);
        self::assertStringNotContainsString('code_challenge=', $url);
        self::assertStringNotContainsString('state=', $url);
    }

    public function testGetMetadataContainsExpectedEndpoints(): void
    {
        $metadata = $this->service->getMetadata('https://example.com/');

        self::assertSame('https://example.com', $metadata['issuer']);
        self::assertContains('none', $metadata['token_endpoint_auth_methods_supported']);
        self::assertSame('https://example.com/mcp_oauth/register', $metadata['registration_endpoint']);
    }
}
