<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for OAuth token hashing at rest
 */
class OAuthTokenHashingTest extends AbstractFunctionalTest
{
    private OAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
    }

    public function testAccessTokenIsHashedInDatabase(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plainToken);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $row = $connection->createQueryBuilder()
            ->select('token')
            ->from('tx_mcpserver_access_tokens')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        self::assertNotFalse($row);
        self::assertNotEquals($plainToken, $row['token'], 'Plain token must NOT be stored in database');
        self::assertEquals(hash('sha256', $plainToken), $row['token'], 'Stored value must be SHA-256 hash of token');
    }

    public function testHashedTokenCanBeValidated(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken($plainToken);

        self::assertNotNull($result, 'Valid token must be accepted');
        self::assertEquals(1, $result['be_user_uid']);
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->service->createDirectAccessToken(1, 'test-client');
        $result = $this->service->validateToken('0000000000000000000000000000000000000000000000000000000000000000');

        self::assertNull($result, 'Wrong token must be rejected');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->update('tx_mcpserver_access_tokens', ['expires' => time() - 1], ['be_user_uid' => 1]);

        self::assertNull($this->service->validateToken($plainToken), 'Expired token must be rejected');
    }

    public function testTokenHashingWorksForCodeExchange(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $tokenData = $this->service->exchangeCodeForToken($code);

        self::assertNotNull($tokenData);
        $result = $this->service->validateToken($tokenData['access_token']);
        self::assertNotNull($result);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $row = $connection->createQueryBuilder()
            ->select('token')
            ->from('tx_mcpserver_access_tokens')
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        self::assertNotEquals($tokenData['access_token'], $row['token']);
    }

    public function testLegacyPlainTextTokenIsRejected(): void
    {
        // Pre-migration plaintext-token fallback was removed in the
        // 2026-05-03 typo3-security pass (RFC 9700 alignment). Operators
        // who still have token_version=0 rows must re-issue tokens via the
        // backend module — the SHA-256-only path is the supported one.
        $plainToken = bin2hex(random_bytes(32));
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $connection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => $plainToken,
            'token_version' => 0,
            'be_user_uid' => 1, 'client_name' => 'legacy-client',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        $result = $this->service->validateToken($plainToken);
        self::assertNull($result, 'Plaintext (token_version=0) tokens must no longer validate.');
    }

    public function testLegacyPlainTextTokenInvalidAfterWizardMigration(): void
    {
        $plainToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $plainToken);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        // Simulate post-wizard state: token is hashed, version=1
        $connection->insert('tx_mcpserver_access_tokens', [
            'pid' => 0, 'tstamp' => time(), 'crdate' => time(),
            'token' => $hashedToken,
            'token_version' => 1,
            'be_user_uid' => 1, 'client_name' => 'legacy-client',
            'expires' => time() + 86400,
            'last_used' => time(), 'created_ip' => '', 'last_used_ip' => '',
        ]);

        // The plain token should validate (hash lookup: hash(plain) == stored hash)
        $result = $this->service->validateToken($plainToken);
        self::assertNotNull($result, 'Migrated tokens must validate via hash lookup');

        // The raw hash should NOT validate (hash(hash) != stored hash)
        self::assertNull($this->service->validateToken($hashedToken), 'Raw hash value must not validate');
    }

    public function testRevokedTokenIsRejected(): void
    {
        $plainToken = $this->service->createDirectAccessToken(1, 'test-client');
        $valid = $this->service->validateToken($plainToken);
        self::assertNotNull($valid);

        $this->service->revokeToken($valid['token_uid'], 1);
        self::assertNull($this->service->validateToken($plainToken), 'Revoked token must be rejected');
    }

    public function testAuthorizationCodeIsOneTimeUse(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        self::assertNotNull($this->service->exchangeCodeForToken($code));
        self::assertNull($this->service->exchangeCodeForToken($code), 'Second exchange must fail');
    }

    public function testExpiredAuthorizationCodeIsRejected(): void
    {
        $code = $this->service->createAuthorizationCode(1, 'test-client');
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_oauth_codes');
        $connection->update('tx_mcpserver_oauth_codes', ['expires' => time() - 1], ['code' => $code]);

        self::assertNull($this->service->exchangeCodeForToken($code));
    }
}
