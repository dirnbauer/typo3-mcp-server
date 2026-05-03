<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * OAuth service for MCP server authentication
 */
final readonly class OAuthService
{
    private const CLIENT_ID = 'typo3-mcp-server';
    private const CODE_EXPIRY_SECONDS = 600; // 10 minutes
    private const TOKEN_EXPIRY_SECONDS = 2592000; // 30 days
    private const REFRESH_TOKEN_EXPIRY_SECONDS = 7776000; // 90 days
    private const SUPPORTED_GRANT_TYPES = ['authorization_code', 'refresh_token'];
    private const SUPPORTED_RESPONSE_TYPES = ['code'];

    private LoggerInterface $logger;

    public function __construct(
        private ConnectionPool $connectionPool,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

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
            throw new \InvalidArgumentException('Only S256 PKCE challenges are supported');
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
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}|null
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

        // Auth-failure logging — these are the branches an attacker would
        // probe; warning-level so production log monitoring picks them up.
        $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
        if (!$authCode) {
            $this->logger->warning('OAuth: authorization code lookup failed (invalid or expired)', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $pkceChallenge = is_string($authCode['pkce_challenge'] ?? null) ? $authCode['pkce_challenge'] : '';
        if ($pkceChallenge !== '') {
            $challengeMethod = is_string($authCode['pkce_challenge_method'] ?? null) ? $authCode['pkce_challenge_method'] : '';
            if ($challengeMethod !== 'S256') {
                $this->logger->warning('OAuth: rejected non-S256 PKCE method', [
                    'client_ip' => $clientIp,
                    'method' => $challengeMethod,
                ]);
                return null;
            }
            if ($codeVerifier === null || $codeVerifier === '') {
                $this->logger->warning('OAuth: PKCE code_verifier missing on token exchange', [
                    'client_ip' => $clientIp,
                ]);
                return null;
            }
            $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($pkceChallenge, $computedChallenge)) {
                $this->logger->warning('OAuth: PKCE challenge/verifier mismatch', [
                    'client_ip' => $clientIp,
                ]);
                return null;
            }
        }

        // Generate access token
        $accessToken = $this->generateSecureToken();
        $refreshToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;
        $refreshExpires = time() + self::REFRESH_TOKEN_EXPIRY_SECONDS;

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
                'refresh_token' => hash('sha256', $refreshToken),
                'token_version' => 1,
                'be_user_uid' => $authCode['be_user_uid'],
                'client_name' => $authCode['client_name'],
                'expires' => $expires,
                'refresh_expires' => $refreshExpires,
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
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRY_SECONDS,
        ];
    }

    /**
     * Rotate a refresh token and return a fresh access token pair.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}|null
     */
    public function refreshAccessToken(string $refreshToken, ?ServerRequestInterface $request = null): ?array
    {
        if ($refreshToken === '') {
            return null;
        }

        $refreshTokenHash = hash('sha256', $refreshToken);
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_access_tokens');

        $queryBuilder = $connection->createQueryBuilder();
        $tokenRecord = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_access_tokens')
            ->where(
                $queryBuilder->expr()->eq('refresh_token', $queryBuilder->createNamedParameter($refreshTokenHash)),
                $queryBuilder->expr()->gt('refresh_expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$tokenRecord) {
            $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
            $this->logger->warning('OAuth: refresh-token rotation failed (invalid or expired)', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $accessToken = $this->generateSecureToken();
        $newRefreshToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;
        $refreshExpires = time() + self::REFRESH_TOKEN_EXPIRY_SECONDS;

        $clientIp = '';
        if ($request !== null) {
            $clientIp = $this->getRemoteAddress($request);
        }

        $connection->update(
            'tx_mcpserver_access_tokens',
            [
                'tstamp' => time(),
                'token' => hash('sha256', $accessToken),
                'refresh_token' => hash('sha256', $newRefreshToken),
                'token_version' => 1,
                'expires' => $expires,
                'refresh_expires' => $refreshExpires,
                'last_used' => time(),
                'last_used_ip' => $clientIp,
            ],
            ['uid' => $tokenRecord['uid']],
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
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
            // No plaintext fallback (RFC 9700 §4.13: avoid weak comparisons).
            // Pre-migration tokens (token_version=0, plaintext) are no longer
            // honored — affected MCP clients re-authenticate via the backend
            // module, which issues a freshly hashed token.
            $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
            $this->logger->warning('OAuth: bearer-token validation failed', [
                'client_ip' => $clientIp,
            ]);
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
            'be_user_uid' => is_numeric($tokenRecord['be_user_uid'] ?? null) ? (int)$tokenRecord['be_user_uid'] : 0,
            'client_name' => is_string($tokenRecord['client_name'] ?? null) ? $tokenRecord['client_name'] : '',
            'token_uid' => is_numeric($tokenRecord['uid'] ?? null) ? (int)$tokenRecord['uid'] : 0,
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
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->gt('expires', $queryBuilder->createNamedParameter(time())),
                    $queryBuilder->expr()->gt('refresh_expires', $queryBuilder->createNamedParameter(time())),
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $normalizedTokens = [];
        foreach ($tokens as $token) {
            $normalizedTokens[] = [
                'uid' => is_numeric($token['uid'] ?? null) ? (int)$token['uid'] : 0,
                'client_name' => is_string($token['client_name'] ?? null) ? $token['client_name'] : '',
                'token' => is_string($token['token'] ?? null) ? $token['token'] : '',
                'crdate' => is_numeric($token['crdate'] ?? null) ? (int)$token['crdate'] : 0,
                'expires' => is_numeric($token['expires'] ?? null) ? (int)$token['expires'] : 0,
                'last_used' => is_numeric($token['last_used'] ?? null) ? (int)$token['last_used'] : 0,
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
                $tokenQueryBuilder->expr()->or(
                    $tokenQueryBuilder->expr()->eq('refresh_token', $tokenQueryBuilder->createNamedParameter('')),
                    $tokenQueryBuilder->expr()->lt('refresh_expires', $tokenQueryBuilder->createNamedParameter($currentTime)),
                ),
            )
            ->executeStatement();
    }

    /**
     * Register the fixed public OAuth client used by MCP integrations.
     *
     * @param array<string, mixed> $clientData
     * @return array{
     *   client_id: string,
     *   client_name: string,
     *   grant_types: list<string>,
     *   response_types: list<string>,
     *   scope: string,
     *   redirect_uris: list<string>
     * }
     */
    public function registerClient(array $clientData): array
    {
        $clientName = is_string($clientData['client_name'] ?? null) && $clientData['client_name'] !== ''
            ? $clientData['client_name']
            : 'MCP Client';
        $scope = is_string($clientData['scope'] ?? null) && $clientData['scope'] !== ''
            ? $clientData['scope']
            : 'mcp_access';

        return [
            'client_id' => self::CLIENT_ID,
            'client_name' => $clientName,
            'grant_types' => $this->normalizeSupportedStringList($clientData['grant_types'] ?? null, self::SUPPORTED_GRANT_TYPES),
            'response_types' => $this->normalizeSupportedStringList($clientData['response_types'] ?? null, self::SUPPORTED_RESPONSE_TYPES),
            'scope' => $scope,
            'redirect_uris' => $this->normalizeStringList($clientData['redirect_uris'] ?? null, ['http://localhost']),
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
            'response_types_supported' => self::SUPPORTED_RESPONSE_TYPES,
            'grant_types_supported' => self::SUPPORTED_GRANT_TYPES,
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
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
                'refresh_token' => '',
                'token_version' => 1,
                'be_user_uid' => $beUserId,
                'client_name' => $clientName,
                'expires' => $expires,
                'refresh_expires' => 0,
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
        return is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : '';
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function normalizeStringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $normalized = array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
        return $normalized !== [] ? $normalized : $default;
    }

    /**
     * @param list<string> $supported
     * @return list<string>
     */
    private function normalizeSupportedStringList(mixed $value, array $supported): array
    {
        $normalized = $this->normalizeStringList($value, $supported);
        $allowed = array_values(array_filter($normalized, static fn(string $item): bool => in_array($item, $supported, true)));

        return $allowed !== [] ? $allowed : $supported;
    }
}
