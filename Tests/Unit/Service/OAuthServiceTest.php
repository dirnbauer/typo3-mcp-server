<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\OAuthService;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OAuthService();
    }

    public function testGenerateAuthorizationUrlContainsClientId(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com');

        $this->assertStringContainsString('client_id=typo3-mcp-server', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function testGenerateAuthorizationUrlContainsRedirectUri(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'TestClient',
            'https://callback.example.com/oauth',
        );

        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('callback.example.com', $url);
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

        $this->assertStringContainsString('code_challenge=challenge-value-123', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
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

        $this->assertStringContainsString('state=random-state-value', $url);
    }

    public function testGenerateAuthorizationUrlStripsTrailingSlash(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com/');

        $this->assertStringStartsWith('https://example.com/mcp_oauth/authorize?', $url);
    }

    public function testGenerateAuthorizationUrlContainsClientName(): void
    {
        $url = $this->service->generateAuthorizationUrl(
            'https://example.com',
            'My Custom Client',
        );

        $this->assertStringContainsString('client_name=My+Custom+Client', $url);
    }

    public function testGenerateAuthorizationUrlWithEmptyOptionalParams(): void
    {
        $url = $this->service->generateAuthorizationUrl('https://example.com');

        $this->assertStringNotContainsString('redirect_uri=', $url);
        $this->assertStringNotContainsString('code_challenge=', $url);
        $this->assertStringNotContainsString('state=', $url);
    }
}
