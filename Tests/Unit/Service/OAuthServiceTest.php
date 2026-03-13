<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\OAuthService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    private OAuthService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new OAuthService();
    }

    #[Test]
    public function metadataOnlyAdvertisesS256Pkce(): void
    {
        $metadata = $this->subject->getMetadata('https://example.org');

        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
    }

    #[Test]
    public function authorizationUrlContainsProvidedOAuthParameters(): void
    {
        $url = $this->subject->generateAuthorizationUrl(
            'https://example.org',
            'Claude Desktop',
            'claude://oauth/callback',
            'challenge-value',
            'S256',
            'state-123',
        );

        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $params);

        self::assertSame('typo3-mcp-server', $params['client_id'] ?? null);
        self::assertSame('Claude Desktop', $params['client_name'] ?? null);
        self::assertSame('claude://oauth/callback', $params['redirect_uri'] ?? null);
        self::assertSame('challenge-value', $params['code_challenge'] ?? null);
        self::assertSame('S256', $params['code_challenge_method'] ?? null);
        self::assertSame('state-123', $params['state'] ?? null);
    }
}
