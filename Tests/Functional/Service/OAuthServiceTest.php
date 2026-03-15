<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class OAuthServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $service = $this->getContainer()->get(OAuthService::class);
        \assert($service instanceof OAuthService);
        $this->service = $service;
    }

    public function testCreateAuthorizationCodeReturnsNonEmptyString(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'TestClient');

        $this->assertNotEmpty($code);
        $this->assertGreaterThan(32, strlen($code));
    }

    public function testCreateAuthorizationCodeStoresInDatabase(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'TestClient');

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from('tx_mcpserver_oauth_codes')
            ->where('code = :code')
            ->setParameter('code', $code)
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['be_user_uid']);
        $this->assertSame('TestClient', $row['client_name']);
    }

    public function testCreateAuthorizationCodeWithPkceStoresChallenge(): void
    {
        $challenge = 'abc123challenge';
        $code = $this->service->createAuthorizationCode(
            1,
            'TestClient',
            'https://callback.example.com',
            $challenge,
            'S256',
        );

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $row = $connection->createQueryBuilder()
            ->select('pkce_challenge', 'pkce_challenge_method', 'redirect_uri')
            ->from('tx_mcpserver_oauth_codes')
            ->where('code = :code')
            ->setParameter('code', $code)
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($row);
        $this->assertSame($challenge, $row['pkce_challenge']);
        $this->assertSame('S256', $row['pkce_challenge_method']);
        $this->assertSame('https://callback.example.com', $row['redirect_uri']);
    }

    public function testCreateAuthorizationCodeRejectsNonS256Method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only S256 PKCE challenges are supported');

        $this->service->createAuthorizationCode(1, 'TestClient', '', 'challenge', 'plain');
    }

    public function testCreateDirectAccessTokenReturnsToken(): void
    {
        $token = $this->service->createDirectAccessToken(1, 'test token');

        $this->assertNotEmpty($token);
        $this->assertGreaterThan(32, strlen($token));
    }

    public function testCreateDirectAccessTokenStoresHashedToken(): void
    {
        $rawToken = $this->service->createDirectAccessToken(1, 'test token');

        $tokens = $this->service->getUserTokens(1);

        $this->assertNotEmpty($tokens);
        $storedToken = $tokens[0]['token'] ?? '';
        $this->assertNotSame($rawToken, $storedToken, 'Stored token should be a hash, not the raw token');
    }

    public function testValidateTokenAcceptsValidToken(): void
    {
        $rawToken = $this->service->createDirectAccessToken(1, 'test token');

        $result = $this->service->validateToken($rawToken);

        $this->assertIsArray($result);
        $this->assertSame(1, (int) ($result['be_user_uid'] ?? 0));
    }

    public function testValidateTokenRejectsInvalidToken(): void
    {
        $this->service->createDirectAccessToken(1, 'test token');

        $result = $this->service->validateToken('invalid-token-value');

        $this->assertNull($result);
    }

    public function testGetUserTokensReturnsAllTokensForUser(): void
    {
        $this->service->createDirectAccessToken(1, 'token-a');
        $this->service->createDirectAccessToken(1, 'token-b');

        $tokens = $this->service->getUserTokens(1);

        $this->assertCount(2, $tokens);
    }

    public function testGetUserTokensReturnsEmptyForUserWithNoTokens(): void
    {
        $tokens = $this->service->getUserTokens(999);

        $this->assertSame([], $tokens);
    }

    public function testRevokeTokenRemovesToken(): void
    {
        $this->service->createDirectAccessToken(1, 'to-revoke');

        $tokens = $this->service->getUserTokens(1);
        $this->assertCount(1, $tokens);

        $tokenUid = $tokens[0]['uid'];
        $result = $this->service->revokeToken($tokenUid, 1);

        $this->assertTrue($result);

        $tokensAfter = $this->service->getUserTokens(1);
        $this->assertCount(0, $tokensAfter);
    }

    public function testRevokeTokenFailsForWrongUser(): void
    {
        $this->service->createDirectAccessToken(1, 'owned-by-user-1');

        $tokens = $this->service->getUserTokens(1);
        $tokenUid = $tokens[0]['uid'];

        $result = $this->service->revokeToken($tokenUid, 999);

        $this->assertFalse($result);
    }

    public function testRevokeAllUserTokensRemovesAllTokens(): void
    {
        $this->service->createDirectAccessToken(1, 'token-a');
        $this->service->createDirectAccessToken(1, 'token-b');
        $this->service->createDirectAccessToken(1, 'token-c');

        $this->assertCount(3, $this->service->getUserTokens(1));

        $count = $this->service->revokeAllUserTokens(1);

        $this->assertSame(3, $count);
        $this->assertCount(0, $this->service->getUserTokens(1));
    }

    public function testRevokeUserTokensByClientNameRemovesOnlyMatchingTokens(): void
    {
        $this->service->createDirectAccessToken(1, 'n8n token');
        $this->service->createDirectAccessToken(1, 'manus token');

        $this->service->revokeUserTokensByClientName(1, 'n8n token');

        $remaining = $this->service->getUserTokens(1);
        $this->assertCount(1, $remaining);
        $this->assertSame('manus token', $remaining[0]['client_name']);
    }

    public function testExchangeCodeForTokenWithValidCode(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'TestClient');

        $result = $this->service->exchangeCodeForToken($code);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('token_type', $result);
        $this->assertArrayHasKey('expires_in', $result);
        $this->assertSame('Bearer', $result['token_type']);
    }

    public function testExchangeCodeForTokenReturnsNullForInvalidCode(): void
    {
        $result = $this->service->exchangeCodeForToken('invalid-code');

        $this->assertNull($result);
    }

    public function testExchangeCodeForTokenConsumesCode(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'TestClient');

        $first = $this->service->exchangeCodeForToken($code);
        $this->assertIsArray($first);

        $second = $this->service->exchangeCodeForToken($code);
        $this->assertNull($second, 'Code should only be usable once');
    }
}
