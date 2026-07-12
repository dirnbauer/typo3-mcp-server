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
        assert($service instanceof OAuthService);
        $this->service = $service;
    }

    public function testCreateAuthorizationCodeReturnsNonEmptyString(): void
    {
        [$code] = $this->createPkceAuthorizationCode();

        self::assertNotEmpty($code);
        self::assertGreaterThan(32, strlen($code));
    }

    public function testCreateAuthorizationCodeStoresInDatabase(): void
    {
        [$code] = $this->createPkceAuthorizationCode();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $row = $connection->createQueryBuilder()
            ->select('*')
            ->from('tx_mcpserver_oauth_codes')
            ->where('code = :code')
            ->setParameter('code', hash('sha256', $code))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(1, (int)$row['be_user_uid']);
        self::assertSame('TestClient', $row['client_name']);
    }

    public function testCreateAuthorizationCodeWithPkceStoresChallenge(): void
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
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
            ->setParameter('code', hash('sha256', $code))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame($challenge, $row['pkce_challenge']);
        self::assertSame('S256', $row['pkce_challenge_method']);
        self::assertSame('https://callback.example.com', $row['redirect_uri']);
    }

    public function testCreateAuthorizationCodeRejectsNonS256Method(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PKCE requires a valid S256 code challenge');

        $this->service->createAuthorizationCode(1, 'TestClient', '', str_repeat('a', 43), 'plain');
    }

    public function testCreateDirectAccessTokenReturnsToken(): void
    {
        $token = $this->service->createDirectAccessToken(1, 'test token');

        self::assertNotEmpty($token);
        self::assertGreaterThan(32, strlen($token));
    }

    public function testCreateDirectAccessTokenStoresHashedToken(): void
    {
        $rawToken = $this->service->createDirectAccessToken(1, 'test token');

        $tokens = $this->service->getUserTokens(1);

        self::assertNotEmpty($tokens);
        $storedToken = $tokens[0]['token'] ?? '';
        self::assertNotSame($rawToken, $storedToken, 'Stored token should be a hash, not the raw token');
    }

    public function testValidateTokenAcceptsValidToken(): void
    {
        $rawToken = $this->service->createDirectAccessToken(1, 'test token');

        $result = $this->service->validateToken($rawToken);

        self::assertIsArray($result);
        self::assertSame(1, (int)($result['be_user_uid'] ?? 0));
    }

    public function testValidateTokenRejectsInvalidToken(): void
    {
        $this->service->createDirectAccessToken(1, 'test token');

        $result = $this->service->validateToken('invalid-token-value');

        self::assertNull($result);
    }

    public function testGetUserTokensReturnsAllTokensForUser(): void
    {
        $this->service->createDirectAccessToken(1, 'token-a');
        $this->service->createDirectAccessToken(1, 'token-b');

        $tokens = $this->service->getUserTokens(1);

        self::assertCount(2, $tokens);
    }

    public function testGetUserTokensReturnsEmptyForUserWithNoTokens(): void
    {
        $tokens = $this->service->getUserTokens(999);

        self::assertSame([], $tokens);
    }

    public function testRevokeTokenRemovesToken(): void
    {
        $this->service->createDirectAccessToken(1, 'to-revoke');

        $tokens = $this->service->getUserTokens(1);
        self::assertCount(1, $tokens);

        $tokenUid = $tokens[0]['uid'];
        $result = $this->service->revokeToken($tokenUid, 1);

        self::assertTrue($result);

        $tokensAfter = $this->service->getUserTokens(1);
        self::assertCount(0, $tokensAfter);
    }

    public function testRevokeTokenFailsForWrongUser(): void
    {
        $this->service->createDirectAccessToken(1, 'owned-by-user-1');

        $tokens = $this->service->getUserTokens(1);
        $tokenUid = $tokens[0]['uid'];

        $result = $this->service->revokeToken($tokenUid, 999);

        self::assertFalse($result);
    }

    public function testRevokeAllUserTokensRemovesAllTokens(): void
    {
        $this->service->createDirectAccessToken(1, 'token-a');
        $this->service->createDirectAccessToken(1, 'token-b');
        $this->service->createDirectAccessToken(1, 'token-c');

        self::assertCount(3, $this->service->getUserTokens(1));

        $count = $this->service->revokeAllUserTokens(1);

        self::assertSame(3, $count);
        self::assertCount(0, $this->service->getUserTokens(1));
    }

    public function testRevokeUserTokensByClientNameRemovesOnlyMatchingTokens(): void
    {
        $this->service->createDirectAccessToken(1, 'n8n token');
        $this->service->createDirectAccessToken(1, 'manus token');

        $this->service->revokeUserTokensByClientName(1, 'n8n token');

        $remaining = $this->service->getUserTokens(1);
        self::assertCount(1, $remaining);
        self::assertSame('manus token', $remaining[0]['client_name']);
    }

    public function testExchangeCodeForTokenWithValidCode(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();

        $result = $this->service->exchangeCodeForToken($code, $verifier);

        self::assertIsArray($result);
        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertArrayHasKey('token_type', $result);
        self::assertArrayHasKey('expires_in', $result);
        self::assertSame('Bearer', $result['token_type']);
    }

    public function testRefreshAccessTokenRotatesTokenPair(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();
        $first = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($first);

        $second = $this->service->refreshAccessToken($first['refresh_token']);
        self::assertIsArray($second);
        self::assertNotSame($first['access_token'], $second['access_token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);

        self::assertNull($this->service->validateToken($first['access_token']));
        self::assertIsArray($this->service->validateToken($second['access_token']));
        self::assertNull($this->service->refreshAccessToken($first['refresh_token']));
        self::assertNull(
            $this->service->validateToken($second['access_token']),
            'Replaying a rotated refresh token must revoke the complete token family.',
        );
    }

    public function testRefreshAccessTokenRejectsInvalidToken(): void
    {
        self::assertNull($this->service->refreshAccessToken('invalid-refresh-token'));
    }

    public function testCleanupKeepsExpiredAccessTokenWithValidRefreshToken(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();
        $first = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($first);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->update('tx_mcpserver_access_tokens', ['expires' => time() - 1], ['be_user_uid' => 1]);

        $this->service->cleanupExpired();

        self::assertIsArray($this->service->refreshAccessToken($first['refresh_token']));
    }

    public function testExchangeCodeForTokenReturnsNullForInvalidCode(): void
    {
        $result = $this->service->exchangeCodeForToken('invalid-code');

        self::assertNull($result);
    }

    public function testExchangeCodeForTokenConsumesCode(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();

        $first = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($first);

        $second = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertNull($second, 'Code should only be usable once');
    }

    public function testDisabledBackendUserInvalidatesBearerAndRefreshTokensImmediately(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();
        $tokens = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($tokens);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
        $connection->update('be_users', ['disable' => 1], ['uid' => 1]);

        self::assertNull($this->service->validateToken($tokens['access_token']));
        self::assertNull($this->service->refreshAccessToken($tokens['refresh_token']));
    }

    public function testRefreshRotationNeverExtendsTheAbsoluteFamilyLifetime(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();
        $tokens = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($tokens);

        $familyExpires = time() + 60;
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->update(
            'tx_mcpserver_access_tokens',
            ['refresh_family_expires' => $familyExpires],
            ['be_user_uid' => 1],
        );

        self::assertIsArray($this->service->refreshAccessToken($tokens['refresh_token']));
        $storedRefreshExpiry = $connection->select(
            ['refresh_expires'],
            'tx_mcpserver_access_tokens',
            ['be_user_uid' => 1],
        )->fetchOne();

        self::assertSame($familyExpires, (int)$storedRefreshExpiry);
    }

    public function testReplayFromAnyEarlierRefreshGenerationRevokesTheCurrentFamily(): void
    {
        [$code, $verifier] = $this->createPkceAuthorizationCode();
        $first = $this->service->exchangeCodeForToken($code, $verifier);
        self::assertIsArray($first);
        $second = $this->service->refreshAccessToken($first['refresh_token']);
        self::assertIsArray($second);
        $third = $this->service->refreshAccessToken($second['refresh_token']);
        self::assertIsArray($third);
        self::assertIsArray($this->service->validateToken($third['access_token']));

        self::assertNull($this->service->refreshAccessToken($first['refresh_token']));
        self::assertNull($this->service->validateToken($third['access_token']));
    }

    public function testDynamicRegistrationPersistsExactSecureRedirectUris(): void
    {
        $registered = $this->service->registerClient([
            'client_name' => 'Remote editor',
            'redirect_uris' => ['https://client.example/oauth/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);

        $loaded = $this->service->getRegisteredClient($registered['client_id']);

        self::assertIsArray($loaded);
        self::assertSame(['https://client.example/oauth/callback'], $loaded['redirect_uris']);
        self::assertTrue($this->service->isRedirectUriAllowed(
            $registered['client_id'],
            'https://client.example/oauth/callback',
        ));
        self::assertFalse($this->service->isRedirectUriAllowed(
            $registered['client_id'],
            'https://client.example/oauth/callback/',
        ));
    }

    public function testAuthorizationCodeRefreshAndBearerStayBoundToClientAndResource(): void
    {
        $client = $this->service->registerClient([
            'client_name' => 'Bound client',
            'redirect_uris' => ['https://client.example/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);
        $resource = 'https://cms.example/mcp';
        $verifier = str_repeat('c', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $this->service->createAuthorizationCode(
            1,
            'Bound client',
            'https://client.example/callback',
            $challenge,
            'S256',
            $client['client_id'],
            $resource,
        );

        self::assertNull($this->service->exchangeCodeForToken(
            $code,
            $verifier,
            clientId: 'wrong-client',
            redirectUri: 'https://client.example/callback',
            resource: $resource,
        ));
        self::assertNull($this->service->exchangeCodeForToken(
            $code,
            $verifier,
            clientId: $client['client_id'],
            redirectUri: 'https://client.example/callback',
            resource: 'https://other.example/mcp',
        ));

        $tokens = $this->service->exchangeCodeForToken(
            $code,
            $verifier,
            clientId: $client['client_id'],
            redirectUri: 'https://client.example/callback',
            resource: $resource,
        );
        self::assertIsArray($tokens);
        self::assertIsArray($this->service->validateToken(
            $tokens['access_token'],
            expectedResource: $resource,
            requiredScope: OAuthService::DEFAULT_SCOPE,
        ));
        self::assertNull($this->service->validateToken(
            $tokens['access_token'],
            expectedResource: 'https://other.example/mcp',
            requiredScope: OAuthService::DEFAULT_SCOPE,
        ));

        self::assertNull($this->service->refreshAccessToken(
            $tokens['refresh_token'],
            clientId: $client['client_id'],
            resource: 'https://other.example/mcp',
        ));
        self::assertIsArray($this->service->refreshAccessToken(
            $tokens['refresh_token'],
            clientId: $client['client_id'],
            resource: $resource,
        ));
    }

    public function testPkceVerifierSyntaxIsValidatedBeforeHashComparison(): void
    {
        $verifier = str_repeat('d', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $this->service->createAuthorizationCode(1, 'TestClient', pkceChallenge: $challenge);

        self::assertNull($this->service->exchangeCodeForToken($code, str_repeat('!', 64)));
    }

    /** @return array{string, string} */
    private function createPkceAuthorizationCode(string $clientName = 'TestClient'): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            $this->service->createAuthorizationCode(1, $clientName, pkceChallenge: $challenge),
            $verifier,
        ];
    }
}
