<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use InvalidArgumentException;
use TYPO3\CMS\Core\Database\Connection;
use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * OAuth service for MCP server authentication
 */
final class OAuthService
{
    private const CLIENT_ID = 'typo3-mcp-server';
    private const CODE_EXPIRY_SECONDS = 600; // 10 minutes
    private const TOKEN_EXPIRY_SECONDS = 2592000; // 30 days

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Generate authorization URL for OAuth flow
     */
    public function generateAuthorizationUrl(string $baseUrl, string $clientName = '', string $redirectUri = '', string $codeChallenge = '', string $challengeMethod = 'S256', string $state = ''): string
    {
        $params = [
            'client_id' => self::CLIENT_ID,
            'response_type' => 'code',
            'client_name' => $clientName,
        ];

        if (!empty($redirectUri)) {
            $params['redirect_uri'] = $redirectUri;
        }

        if (!empty($codeChallenge)) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = $challengeMethod;
        }

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return rtrim($baseUrl, '/') . '/mcp_oauth/authorize?' . http_build_query($params);
    }

    /**
     * Create authorization code for authenticated user
     */
    public function createAuthorizationCode(int $beUserId, string $clientName, string $redirectUri = '', string $pkceChallenge = '', string $challengeMethod = 'S256'): string
    {
        if ($pkceChallenge !== '' && $challengeMethod !== 'S256') {
            throw new InvalidArgumentException('Only S256 PKCE challenges are supported');
        }

        $code = $this->generateSecureToken();
        $expires = time() + self::CODE_EXPIRY_SECONDS;

        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $connection->insert(
            'tx_mcpserver_oauth_codes',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'code' => $code,
                'be_user_uid' => $beUserId,
                'client_name' => $clientName,
                'pkce_challenge' => $pkceChallenge,
                'pkce_challenge_method' => $challengeMethod,
                'redirect_uri' => $redirectUri,
                'expires' => $expires,
            ],
        );

        return $code;
    }

    /**
     * Exchange authorization code for access token
     *
     * @return array{access_token: string, token_type: string, expires_in: int}|null
     */
    public function exchangeCodeForToken(string $code, ?string $codeVerifier = null, ?ServerRequestInterface $request = null): ?array
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_codes');

        $queryBuilder = $connection->createQueryBuilder();
        $authCode = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_oauth_codes')
            ->where(
                $queryBuilder->expr()->eq('code', $queryBuilder->createNamedParameter($code)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$authCode) {
            return null;
        }

        $pkceChallenge = \is_string($authCode['pkce_challenge'] ?? null) ? $authCode['pkce_challenge'] : '';
        if ($pkceChallenge !== '') {
            $challengeMethod = \is_string($authCode['pkce_challenge_method'] ?? null) ? $authCode['pkce_challenge_method'] : '';
            if ($challengeMethod !== 'S256') {
                return null;
            }
            if ($codeVerifier === null || $codeVerifier === '') {
                return null;
            }
            $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($pkceChallenge, $computedChallenge)) {
                return null;
            }
        }

        // Generate access token
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $this->getRemoteAddress($request);
        }

        $tokenConnection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $tokenConnection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => hash('sha256', $accessToken),
                'be_user_uid' => $authCode['be_user_uid'],
                'client_name' => $authCode['client_name'],
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
            ],
        );

        // Delete the authorization code (one-time use)
        $connection->delete(
            'tx_mcpserver_oauth_codes',
            ['uid' => $authCode['uid']],
        );

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRY_SECONDS,
        ];
    }

    /**
     * Validate access token and return user info
     *
     * @return array{be_user_uid: int, client_name: string, token_uid: int}|null
     */
    public function validateToken(string $token, ?ServerRequestInterface $request = null): ?array
    {
        $tokenHash = hash('sha256', $token);

        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $queryBuilder = $connection->createQueryBuilder();
        $tokenRecord = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($tokenHash)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$tokenRecord) {
            return null;
        }

        // Update last used timestamp and IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $this->getRemoteAddress($request);
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->update('tx_mcpserver_access_tokens')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($tokenRecord['uid'])))
            ->set('last_used', time())
            ->set('last_used_ip', $clientIp)
            ->executeStatement();

        return [
            'be_user_uid' => is_numeric($tokenRecord['be_user_uid'] ?? null) ? (int) $tokenRecord['be_user_uid'] : 0,
            'client_name' => \is_string($tokenRecord['client_name'] ?? null) ? $tokenRecord['client_name'] : '',
            'token_uid' => is_numeric($tokenRecord['uid'] ?? null) ? (int) $tokenRecord['uid'] : 0,
        ];
    }

    /**
     * Get all active tokens for a user
     *
     * @return list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}>
     */
    public function getUserTokens(int $beUserId): array
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $queryBuilder = $connection->createQueryBuilder();
        $tokens = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('be_user_uid', $queryBuilder->createNamedParameter($beUserId)),
                $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $normalizedTokens = [];
        foreach ($tokens as $token) {
            $normalizedTokens[] = [
                'uid' => is_numeric($token['uid'] ?? null) ? (int) $token['uid'] : 0,
                'client_name' => \is_string($token['client_name'] ?? null) ? $token['client_name'] : '',
                'token' => \is_string($token['token'] ?? null) ? $token['token'] : '',
                'crdate' => is_numeric($token['crdate'] ?? null) ? (int) $token['crdate'] : 0,
                'expires' => is_numeric($token['expires'] ?? null) ? (int) $token['expires'] : 0,
                'last_used' => is_numeric($token['last_used'] ?? null) ? (int) $token['last_used'] : 0,
            ];
        }

        return $normalizedTokens;
    }

    /**
     * Revoke a specific token
     */
    public function revokeToken(int $tokenUid, int $beUserId): bool
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $affectedRows = $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            [
                'uid' => $tokenUid,
                'be_user_uid' => $beUserId,
            ],
        );

        return $affectedRows > 0;
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllUserTokens(int $beUserId): int
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        return $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            ['be_user_uid' => $beUserId],
        );
    }

    public function revokeUserTokensByClientName(int $beUserId, string $clientName): int
    {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        return $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            [
                'be_user_uid' => $beUserId,
                'client_name' => $clientName,
            ],
        );
    }

    /**
     * Clean up expired codes and tokens
     */
    public function cleanupExpired(): void
    {
        $currentTime = time();

        // Clean up expired authorization codes
        $codeConnection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_codes');
        $codeQueryBuilder = $codeConnection->createQueryBuilder();
        $codeQueryBuilder
            ->delete('tx_mcpserver_oauth_codes')
            ->where(
                $codeQueryBuilder->expr()->lt('expires', $codeQueryBuilder->createNamedParameter($currentTime)),
            )
            ->executeStatement();

        // Mark expired tokens as deleted
        $tokenConnection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');
        $tokenQueryBuilder = $tokenConnection->createQueryBuilder();
        $tokenQueryBuilder
            ->update('tx_mcpserver_access_tokens')
            ->set('deleted', $tokenQueryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            ->set('tstamp', $tokenQueryBuilder->createNamedParameter($currentTime, Connection::PARAM_INT))
            ->where(
                $tokenQueryBuilder->expr()->lt('expires', $tokenQueryBuilder->createNamedParameter($currentTime)),
            )
            ->executeStatement();
    }

    /**
     * Register a new OAuth client dynamically
     *
     * @param array<string, mixed> $clientData
     * @return array<string, mixed>
     */
    public function registerClient(array $clientData): array
    {
        // Generate client credentials
        $clientId = 'mcp_client_' . bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));

        // For now, store in database (could be enhanced later)
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_clients');

        // Check if table exists, if not create it on the fly
        try {
            $connection->insert(
                'tx_mcpserver_oauth_clients',
                [
                    'pid' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'client_name' => $clientData['client_name'] ?? 'MCP Client',
                    'redirect_uris' => $this->encodeJsonValue($clientData['redirect_uris'] ?? []),
                    'grant_types' => $this->encodeJsonValue($clientData['grant_types'] ?? ['authorization_code']),
                    'scope' => $clientData['scope'] ?? 'mcp_access',
                ],
            );
        } catch (Exception) {
            // If table doesn't exist, we'll use the fixed client approach for now
            return [
                'client_id' => self::CLIENT_ID,
                'client_name' => $clientData['client_name'] ?? 'MCP Client',
                'grant_types' => ['authorization_code'],
                'response_types' => ['code'],
                'scope' => 'mcp_access',
                'redirect_uris' => $clientData['redirect_uris'] ?? ['http://localhost'],
            ];
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'client_name' => $clientData['client_name'] ?? 'MCP Client',
            'grant_types' => $clientData['grant_types'] ?? ['authorization_code'],
            'response_types' => ['code'],
            'scope' => $clientData['scope'] ?? 'mcp_access',
            'redirect_uris' => $clientData['redirect_uris'] ?? ['http://localhost'],
        ];
    }

    /**
     * Get OAuth metadata for discovery
     *
     * @return array<string, mixed>
     */
    public function getMetadata(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        return [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/mcp_oauth/authorize',
            'token_endpoint' => $baseUrl . '/mcp_oauth/token',
            'registration_endpoint' => $baseUrl . '/mcp_oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'registration_endpoint_auth_methods_supported' => ['none'],
        ];
    }

    /**
     * Create access token directly (bypassing authorization code flow)
     */
    public function createDirectAccessToken(int $beUserId, string $clientName, ?ServerRequestInterface $request = null): string
    {
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        // Get client IP
        $clientIp = '';
        if ($request !== null) {
            $clientIp = $this->getRemoteAddress($request);
        }

        // Create access token
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $connection->insert(
            'tx_mcpserver_access_tokens',
            [
                'pid' => 0,
                'tstamp' => time(),
                'crdate' => time(),
                'token' => hash('sha256', $accessToken),
                'be_user_uid' => $beUserId,
                'client_name' => $clientName,
                'expires' => $expires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
            ],
        );

        return $accessToken;
    }

    /**
     * Generate cryptographically secure token
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getRemoteAddress(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return \is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : '';
    }

    private function encodeJsonValue(mixed $value): string
    {
        $json = json_encode($value);
        return \is_string($json) ? $json : '[]';
    }
}
