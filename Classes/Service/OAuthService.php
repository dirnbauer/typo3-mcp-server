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
    public const BUILT_IN_CLIENT_ID = 'typo3-mcp-server';
    public const DEFAULT_SCOPE = 'mcp_access';

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
    public function generateAuthorizationUrl(
        string $baseUrl,
        string $clientName = '',
        string $redirectUri = '',
        string $codeChallenge = '',
        string $challengeMethod = 'S256',
        string $state = '',
        string $resource = '',
        string $scope = self::DEFAULT_SCOPE,
    ): string {
        $resource = $resource !== '' ? $resource : rtrim($baseUrl, '/') . '/mcp';
        if ($challengeMethod !== 'S256' || !$this->isValidPkceChallenge($codeChallenge)) {
            throw new \InvalidArgumentException('PKCE requires a valid S256 code challenge');
        }
        if ($redirectUri !== '') {
            throw new \InvalidArgumentException(
                'The built-in manual-code client does not accept redirect_uri; register a public client first',
            );
        }
        $params = [
            'client_id' => self::BUILT_IN_CLIENT_ID,
            'response_type' => 'code',
            'client_name' => $clientName,
            'resource' => $resource,
            'scope' => $scope,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return rtrim($baseUrl, '/') . '/mcp_oauth/authorize?' . http_build_query($params);
    }

    /**
     * Create authorization code for authenticated user
     */
    public function createAuthorizationCode(
        int $beUserId,
        string $clientName,
        string $redirectUri = '',
        string $pkceChallenge = '',
        string $challengeMethod = 'S256',
        string $clientId = self::BUILT_IN_CLIENT_ID,
        string $resource = '',
        string $scope = self::DEFAULT_SCOPE,
    ): string {
        if ($challengeMethod !== 'S256' || !$this->isValidPkceChallenge($pkceChallenge)) {
            throw new \InvalidArgumentException('PKCE requires a valid S256 code challenge');
        }
        if (!$this->isBackendUserActive($beUserId)) {
            throw new \InvalidArgumentException('Cannot issue an authorization code for an inactive backend user');
        }
        if ($clientId === '') {
            throw new \InvalidArgumentException('client_id must not be empty');
        }
        if ($resource !== '') {
            $resource = $this->requireCanonicalResourceUri($resource);
        }
        $scope = $this->normalizeScope($scope);

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
                'code' => hash('sha256', $code),
                'be_user_uid' => $beUserId,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'pkce_challenge' => $pkceChallenge,
                'pkce_challenge_method' => $challengeMethod,
                'redirect_uri' => $redirectUri,
                'resource' => $resource,
                'scope' => $scope,
                'expires' => $expires,
            ],
        );

        return $code;
    }

    /**
     * Exchange authorization code for access token
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}|null
     */
    public function exchangeCodeForToken(
        string $code,
        ?string $codeVerifier = null,
        ?ServerRequestInterface $request = null,
        string $clientId = self::BUILT_IN_CLIENT_ID,
        string $redirectUri = '',
        string $resource = '',
        ?string $scope = null,
    ): ?array {
        $connection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_codes');
        $codeHash = hash('sha256', $code);

        $queryBuilder = $connection->createQueryBuilder();
        $authCode = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_oauth_codes')
            ->where(
                $queryBuilder->expr()->eq('code', $queryBuilder->createNamedParameter($codeHash)),
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

        $beUserId = is_numeric($authCode['be_user_uid'] ?? null) ? (int)$authCode['be_user_uid'] : 0;
        if (!$this->isBackendUserActive($beUserId)) {
            $this->logger->warning('OAuth: authorization code belongs to an inactive backend user', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $storedClientId = is_string($authCode['client_id'] ?? null) ? $authCode['client_id'] : '';
        $storedRedirectUri = is_string($authCode['redirect_uri'] ?? null) ? $authCode['redirect_uri'] : '';
        $storedResource = is_string($authCode['resource'] ?? null) ? $authCode['resource'] : '';
        $storedScope = is_string($authCode['scope'] ?? null) ? $authCode['scope'] : '';
        try {
            $requestedScope = $scope !== null ? $this->normalizeScope($scope) : $storedScope;
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (
            $clientId === ''
            || !hash_equals($storedClientId, $clientId)
            || !hash_equals($storedRedirectUri, $redirectUri)
            || !$this->resourcesMatch($storedResource, $resource)
            || !hash_equals($storedScope, $requestedScope)
        ) {
            $this->logger->warning('OAuth: authorization code binding validation failed', [
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
            if (!$this->isValidPkceVerifier($codeVerifier)) {
                $this->logger->warning('OAuth: PKCE code_verifier has invalid syntax', [
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

        // Consume the code atomically before issuing a token. A second
        // concurrent exchange observes zero affected rows and fails closed.
        $consumed = $connection->delete(
            'tx_mcpserver_oauth_codes',
            [
                'uid' => $authCode['uid'],
                'code' => $codeHash,
                'deleted' => 0,
            ],
        );
        if ($consumed !== 1) {
            $this->logger->warning('OAuth: authorization code was already consumed', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        // Generate access token
        $accessToken = $this->generateSecureToken();
        $refreshToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;
        $refreshExpires = time() + self::REFRESH_TOKEN_EXPIRY_SECONDS;
        $refreshFamilyId = bin2hex(random_bytes(32));

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
                'refresh_family_id' => $refreshFamilyId,
                'refresh_family_expires' => $refreshExpires,
                'token_version' => 1,
                'be_user_uid' => $beUserId,
                'client_id' => $storedClientId,
                'client_name' => $authCode['client_name'],
                'resource' => $storedResource,
                'scope' => $storedScope,
                'expires' => $expires,
                'refresh_expires' => $refreshExpires,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
            ],
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRY_SECONDS,
            'scope' => $storedScope,
        ];
    }

    /**
     * Rotate a refresh token and return a fresh access token pair.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}|null
     */
    public function refreshAccessToken(
        string $refreshToken,
        ?ServerRequestInterface $request = null,
        string $clientId = self::BUILT_IN_CLIENT_ID,
        string $resource = '',
        ?string $scope = null,
    ): ?array {
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
            $replayQuery = $connection->createQueryBuilder();
            $replayedFamily = $replayQuery
                ->select('family_id')
                ->from('tx_mcpserver_oauth_refresh_replay')
                ->where(
                    $replayQuery->expr()->eq('token_hash', $replayQuery->createNamedParameter($refreshTokenHash)),
                    $replayQuery->expr()->gt('expires', $replayQuery->createNamedParameter(time())),
                    $replayQuery->expr()->eq('deleted', $replayQuery->createNamedParameter(0)),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if (is_string($replayedFamily) && $replayedFamily !== '') {
                $this->revokeRefreshFamily($connection, $replayedFamily);
                $this->logger->warning('OAuth: stale refresh-token replay detected; token family revoked', [
                    'client_ip' => $clientIp,
                ]);
                return null;
            }
            $this->logger->warning('OAuth: refresh-token rotation failed (invalid or expired)', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
        $beUserId = is_numeric($tokenRecord['be_user_uid'] ?? null) ? (int)$tokenRecord['be_user_uid'] : 0;
        if (!$this->isBackendUserActive($beUserId)) {
            $this->logger->warning('OAuth: refresh token belongs to an inactive backend user', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $refreshFamilyExpires = is_numeric($tokenRecord['refresh_family_expires'] ?? null)
            ? (int)$tokenRecord['refresh_family_expires']
            : 0;
        if ($refreshFamilyExpires <= 0) {
            // Upgrade compatibility for token rows issued before token-family
            // tracking: preserve their existing absolute refresh deadline.
            $refreshFamilyExpires = is_numeric($tokenRecord['refresh_expires'] ?? null)
                ? (int)$tokenRecord['refresh_expires']
                : 0;
        }
        if ($refreshFamilyExpires <= time()) {
            return null;
        }
        $refreshFamilyId = is_string($tokenRecord['refresh_family_id'] ?? null)
            ? $tokenRecord['refresh_family_id']
            : '';
        if ($refreshFamilyId === '') {
            $refreshFamilyId = bin2hex(random_bytes(32));
        }

        $storedClientId = is_string($tokenRecord['client_id'] ?? null) ? $tokenRecord['client_id'] : '';
        $storedResource = is_string($tokenRecord['resource'] ?? null) ? $tokenRecord['resource'] : '';
        $storedScope = is_string($tokenRecord['scope'] ?? null) ? $tokenRecord['scope'] : '';
        try {
            $requestedScope = $scope !== null ? $this->normalizeScope($scope) : $storedScope;
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (
            $clientId === ''
            || !hash_equals($storedClientId, $clientId)
            || !$this->resourcesMatch($storedResource, $resource)
            || !$this->scopeIsSubset($requestedScope, $storedScope)
        ) {
            $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
            $this->logger->warning('OAuth: refresh-token binding validation failed', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $accessToken = $this->generateSecureToken();
        $newRefreshToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;
        $refreshExpires = min(time() + self::REFRESH_TOKEN_EXPIRY_SECONDS, $refreshFamilyExpires);

        try {
            $updated = $connection->transactional(function (Connection $transaction) use (
                $refreshTokenHash,
                $refreshFamilyId,
                $refreshFamilyExpires,
                $accessToken,
                $newRefreshToken,
                $expires,
                $refreshExpires,
                $clientIp,
                $requestedScope,
                $tokenRecord,
            ): bool {
                // Persist every used token hash before changing the current
                // token. The unique key also serializes concurrent double-use.
                $transaction->insert('tx_mcpserver_oauth_refresh_replay', [
                    'pid' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'token_hash' => $refreshTokenHash,
                    'family_id' => $refreshFamilyId,
                    'expires' => $refreshFamilyExpires,
                ]);

                return $transaction->update(
                    'tx_mcpserver_access_tokens',
                    [
                        'tstamp' => time(),
                        'token' => hash('sha256', $accessToken),
                        'refresh_token' => hash('sha256', $newRefreshToken),
                        'refresh_family_id' => $refreshFamilyId,
                        'refresh_family_expires' => $refreshFamilyExpires,
                        'token_version' => 1,
                        'expires' => $expires,
                        'refresh_expires' => $refreshExpires,
                        'last_used' => time(),
                        'last_used_ip' => $clientIp,
                        'scope' => $requestedScope,
                    ],
                    [
                        'uid' => $tokenRecord['uid'],
                        'refresh_token' => $refreshTokenHash,
                        'deleted' => 0,
                    ],
                ) === 1;
            });
        } catch (\Throwable $exception) {
            $this->revokeRefreshFamily($connection, $refreshFamilyId);
            $this->logger->warning('OAuth: concurrent refresh-token replay detected; token family revoked', [
                'client_ip' => $clientIp,
                'exception_class' => $exception::class,
            ]);
            return null;
        }
        if (!$updated) {
            $this->revokeRefreshFamily($connection, $refreshFamilyId);
            $this->logger->warning('OAuth: refresh-token compare-and-swap failed; token family revoked', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_EXPIRY_SECONDS,
            'scope' => $requestedScope,
        ];
    }

    /**
     * Validate access token and return user info
     *
     * @return array{be_user_uid: int, client_id: string, client_name: string, token_uid: int, resource: string, scope: string}|null
     */
    public function validateToken(
        string $token,
        ?ServerRequestInterface $request = null,
        string $expectedResource = '',
        string $requiredScope = '',
    ): ?array {
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

        $beUserId = is_numeric($tokenRecord['be_user_uid'] ?? null) ? (int)$tokenRecord['be_user_uid'] : 0;
        if (!$this->isBackendUserActive($beUserId)) {
            $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
            $this->logger->warning('OAuth: bearer token belongs to an inactive backend user', [
                'client_ip' => $clientIp,
            ]);
            return null;
        }

        $storedResource = is_string($tokenRecord['resource'] ?? null) ? $tokenRecord['resource'] : '';
        $storedScope = is_string($tokenRecord['scope'] ?? null) ? $tokenRecord['scope'] : '';
        if (
            ($expectedResource !== '' && !$this->resourcesMatch($storedResource, $expectedResource))
            || ($requiredScope !== '' && !$this->scopeIsSubset($requiredScope, $storedScope))
        ) {
            $clientIp = $request !== null ? $this->getRemoteAddress($request) : '';
            $this->logger->warning('OAuth: bearer-token audience or scope validation failed', [
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
            'be_user_uid' => $beUserId,
            'client_id' => is_string($tokenRecord['client_id'] ?? null) ? $tokenRecord['client_id'] : '',
            'client_name' => is_string($tokenRecord['client_name'] ?? null) ? $tokenRecord['client_name'] : '',
            'token_uid' => is_numeric($tokenRecord['uid'] ?? null) ? (int)$tokenRecord['uid'] : 0,
            'resource' => $storedResource,
            'scope' => $storedScope,
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

        $replayConnection = $this->connectionPool
            ->getConnectionForTable('tx_mcpserver_oauth_refresh_replay');
        $replayQueryBuilder = $replayConnection->createQueryBuilder();
        $replayQueryBuilder
            ->delete('tx_mcpserver_oauth_refresh_replay')
            ->where(
                $replayQueryBuilder->expr()->lt('expires', $replayQueryBuilder->createNamedParameter($currentTime)),
            )
            ->executeStatement();
    }

    /**
     * Persist a dynamically registered public client (RFC 7591).
     *
     * @param array<string, mixed> $clientData
     * @return array{
     *   client_id: string,
     *   client_id_issued_at: int,
     *   client_name: string,
     *   grant_types: list<string>,
     *   response_types: list<string>,
     *   scope: string,
     *   redirect_uris: list<string>,
     *   token_endpoint_auth_method: string
     * }
     */
    public function registerClient(array $clientData): array
    {
        $clientName = trim(is_string($clientData['client_name'] ?? null) ? $clientData['client_name'] : '');
        if ($clientName === '') {
            $clientName = 'MCP Client';
        }
        if (mb_strlen($clientName) > 255) {
            throw new \InvalidArgumentException('client_name exceeds 255 characters');
        }

        $redirectUris = $this->normalizeRedirectUris($clientData['redirect_uris'] ?? null);
        $grantTypes = $this->requireSupportedStringList(
            $clientData['grant_types'] ?? ['authorization_code'],
            self::SUPPORTED_GRANT_TYPES,
            'grant_types',
        );
        if (!in_array('authorization_code', $grantTypes, true)) {
            throw new \InvalidArgumentException('grant_types must include authorization_code');
        }
        $responseTypes = $this->requireSupportedStringList(
            $clientData['response_types'] ?? ['code'],
            self::SUPPORTED_RESPONSE_TYPES,
            'response_types',
        );
        $scope = $this->normalizeScope(is_string($clientData['scope'] ?? null) ? $clientData['scope'] : self::DEFAULT_SCOPE);
        $authMethod = is_string($clientData['token_endpoint_auth_method'] ?? null)
            ? $clientData['token_endpoint_auth_method']
            : 'none';
        if ($authMethod !== 'none') {
            throw new \InvalidArgumentException('Only public clients using token_endpoint_auth_method=none are supported');
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(24));
        $issuedAt = time();
        $connection = $this->connectionPool->getConnectionForTable('tx_mcpserver_oauth_clients');
        $connection->insert('tx_mcpserver_oauth_clients', [
            'pid' => 0,
            'tstamp' => $issuedAt,
            'crdate' => $issuedAt,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'grant_types' => json_encode($grantTypes, JSON_THROW_ON_ERROR),
            'response_types' => json_encode($responseTypes, JSON_THROW_ON_ERROR),
            'scope' => $scope,
            'token_endpoint_auth_method' => $authMethod,
            'client_id_issued_at' => $issuedAt,
        ]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $issuedAt,
            'client_name' => $clientName,
            'grant_types' => $grantTypes,
            'response_types' => $responseTypes,
            'scope' => $scope,
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => $authMethod,
        ];
    }

    /**
     * @return array{
     *   client_id: string,
     *   client_id_issued_at: int,
     *   client_name: string,
     *   grant_types: list<string>,
     *   response_types: list<string>,
     *   scope: string,
     *   redirect_uris: list<string>,
     *   token_endpoint_auth_method: string
     * }|null
     */
    public function getRegisteredClient(string $clientId): ?array
    {
        if ($clientId === self::BUILT_IN_CLIENT_ID) {
            return [
                'client_id' => self::BUILT_IN_CLIENT_ID,
                'client_id_issued_at' => 0,
                'client_name' => 'TYPO3 MCP Server',
                'grant_types' => self::SUPPORTED_GRANT_TYPES,
                'response_types' => self::SUPPORTED_RESPONSE_TYPES,
                'scope' => self::DEFAULT_SCOPE,
                // The built-in client is deliberately manual-code only. Any
                // callback must use a separately registered DCR client.
                'redirect_uris' => [],
                'token_endpoint_auth_method' => 'none',
            ];
        }
        if ($clientId === '') {
            return null;
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_mcpserver_oauth_clients');
        $queryBuilder = $connection->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from('tx_mcpserver_oauth_clients')
            ->where(
                $queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientId)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        $redirectUris = $this->decodeStringList($row['redirect_uris'] ?? null);
        $grantTypes = $this->decodeStringList($row['grant_types'] ?? null);
        $responseTypes = $this->decodeStringList($row['response_types'] ?? null);

        return [
            'client_id' => is_string($row['client_id'] ?? null) ? $row['client_id'] : '',
            'client_id_issued_at' => is_numeric($row['client_id_issued_at'] ?? null) ? (int)$row['client_id_issued_at'] : 0,
            'client_name' => is_string($row['client_name'] ?? null) ? $row['client_name'] : '',
            'grant_types' => $grantTypes,
            'response_types' => $responseTypes,
            'scope' => is_string($row['scope'] ?? null) ? $row['scope'] : '',
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => is_string($row['token_endpoint_auth_method'] ?? null) ? $row['token_endpoint_auth_method'] : '',
        ];
    }

    public function isValidClientId(string $clientId): bool
    {
        return $this->getRegisteredClient($clientId) !== null;
    }

    public function isRedirectUriAllowed(string $clientId, string $redirectUri): bool
    {
        $client = $this->getRegisteredClient($clientId);
        if ($client === null) {
            return false;
        }

        if ($clientId === self::BUILT_IN_CLIENT_ID) {
            return $redirectUri === '';
        }

        return $redirectUri !== '' && in_array($redirectUri, $client['redirect_uris'], true);
    }

    public function isScopeAllowed(string $clientId, string $scope): bool
    {
        $client = $this->getRegisteredClient($clientId);
        if ($client === null) {
            return false;
        }

        try {
            $scope = $this->normalizeScope($scope);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->scopeIsSubset($scope, $client['scope']);
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
            'scopes_supported' => [self::DEFAULT_SCOPE],
            'client_id_metadata_document_supported' => false,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Create access token directly (bypassing authorization code flow)
     */
    public function createDirectAccessToken(
        int $beUserId,
        string $clientName,
        ?ServerRequestInterface $request = null,
        string $resource = '',
    ): string {
        if (!$this->isBackendUserActive($beUserId)) {
            throw new \InvalidArgumentException('Cannot issue an access token for an inactive backend user');
        }
        $accessToken = $this->generateSecureToken();
        $expires = time() + self::TOKEN_EXPIRY_SECONDS;

        if ($resource === '' && $request !== null) {
            $resource = $this->canonicalMcpResourceFromRequest($request);
        } elseif ($resource !== '') {
            $resource = $this->requireCanonicalResourceUri($resource);
        }

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
                'refresh_family_id' => '',
                'refresh_family_expires' => 0,
                'token_version' => 1,
                'be_user_uid' => $beUserId,
                'client_id' => self::BUILT_IN_CLIENT_ID,
                'client_name' => $clientName,
                'resource' => $resource,
                'scope' => self::DEFAULT_SCOPE,
                'expires' => $expires,
                'refresh_expires' => 0,
                'last_used' => time(),
                'created_ip' => $clientIp,
                'last_used_ip' => $clientIp,
            ],
        );

        return $accessToken;
    }

    private function revokeRefreshFamily(Connection $connection, string $familyId): void
    {
        if ($familyId === '') {
            return;
        }
        $connection->update(
            'tx_mcpserver_access_tokens',
            ['deleted' => 1, 'tstamp' => time()],
            ['refresh_family_id' => $familyId, 'deleted' => 0],
        );
    }

    /**
     * Token validity follows the TYPO3 backend-user lifecycle. Disabling,
     * deleting, or time-limiting an account therefore takes effect for MCP
     * bearer and refresh tokens immediately.
     */
    private function isBackendUserActive(int $beUserId): bool
    {
        if ($beUserId <= 0) {
            return false;
        }

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $queryBuilder = $connection->createQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('starttime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->lte('starttime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid !== false;
    }

    /**
     * Generate cryptographically secure token
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function isValidPkceChallenge(string $challenge): bool
    {
        // S256 is the base64url encoding of one SHA-256 digest: exactly
        // 43 characters without padding.
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) === 1;
    }

    private function isValidPkceVerifier(string $verifier): bool
    {
        $length = strlen($verifier);
        return $length >= 43
            && $length <= 128
            && preg_match('/^[A-Za-z0-9\-._~]+$/D', $verifier) === 1;
    }

    private function getRemoteAddress(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : '';
    }

    /** @return list<string> */
    private function normalizeRedirectUris(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 10) {
            throw new \InvalidArgumentException('redirect_uris must contain between one and ten URIs');
        }

        $redirectUris = [];
        foreach ($value as $redirectUri) {
            if (!is_string($redirectUri) || $redirectUri === '' || strlen($redirectUri) > 2048) {
                throw new \InvalidArgumentException('Each redirect_uri must be a non-empty string of at most 2048 bytes');
            }
            $this->assertSecureRedirectUri($redirectUri);
            $redirectUris[] = $redirectUri;
        }

        return array_values(array_unique($redirectUris));
    }

    private function assertSecureRedirectUri(string $redirectUri): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $redirectUri) === 1) {
            throw new \InvalidArgumentException('redirect_uri contains forbidden characters');
        }

        $parts = parse_url($redirectUri);
        if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('redirect_uri must be an absolute URI without userinfo or fragment');
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(is_string($parts['host'] ?? null) ? trim($parts['host'], '[]') : '');
        if ($scheme === 'https' && $host !== '') {
            return;
        }
        if ($scheme === 'http' && $host !== '' && $this->isLoopbackHost($host)) {
            return;
        }

        throw new \InvalidArgumentException('redirect_uri must use HTTPS or an HTTP loopback host');
    }

    private function isLoopbackHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost');
    }

    /**
     * @param list<string> $supported
     * @return list<string>
     */
    private function requireSupportedStringList(mixed $value, array $supported, string $field): array
    {
        if (!is_array($value) || $value === []) {
            throw new \InvalidArgumentException($field . ' must be a non-empty array');
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $supported, true)) {
                throw new \InvalidArgumentException($field . ' contains an unsupported value');
            }
            $result[] = $item;
        }

        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function decodeStringList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    private function normalizeScope(string $scope): string
    {
        $scopes = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY);
        if ($scopes === false) {
            $scopes = [];
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, [self::DEFAULT_SCOPE]) !== []) {
            throw new \InvalidArgumentException('Only the mcp_access scope is supported');
        }

        return implode(' ', $scopes);
    }

    private function scopeIsSubset(string $requestedScope, string $grantedScope): bool
    {
        $requested = preg_split('/\s+/', trim($requestedScope), -1, PREG_SPLIT_NO_EMPTY);
        $granted = preg_split('/\s+/', trim($grantedScope), -1, PREG_SPLIT_NO_EMPTY);
        if ($requested === false) {
            $requested = [];
        }
        if ($granted === false) {
            $granted = [];
        }

        return $requested !== [] && array_diff($requested, $granted) === [];
    }

    private function resourcesMatch(string $storedResource, string $requestedResource): bool
    {
        if ($storedResource === '' || $requestedResource === '') {
            return $storedResource === $requestedResource;
        }

        try {
            return hash_equals(
                $this->requireCanonicalResourceUri($storedResource),
                $this->requireCanonicalResourceUri($requestedResource),
            );
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function requireCanonicalResourceUri(string $resource): string
    {
        if ($resource === '' || strlen($resource) > 2048 || preg_match('/[\x00-\x20\x7f]/', $resource) === 1) {
            throw new \InvalidArgumentException('Invalid OAuth resource URI');
        }

        $parts = parse_url($resource);
        if (
            !is_array($parts)
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('Invalid OAuth resource URI');
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(is_string($parts['host'] ?? null) ? trim($parts['host'], '[]') : '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('OAuth resource URI must be an absolute HTTP(S) URI');
        }

        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $normalized = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
        $normalized .= is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            $normalized .= '?' . $parts['query'];
        }

        return $normalized;
    }

    public function canonicalMcpResourceFromRequest(ServerRequestInterface $request): string
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $systemConfiguration = is_array($typo3Configuration)
            && is_array($typo3Configuration['SYS'] ?? null)
            ? $typo3Configuration['SYS']
            : [];
        $configuredBaseUrl = $systemConfiguration['reverseProxyBaseUrl'] ?? null;
        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            return $this->requireCanonicalResourceUri(rtrim($configuredBaseUrl, '/') . '/mcp');
        }

        $uri = $request->getUri();
        $host = $uri->getHost();
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $resource = strtolower($uri->getScheme()) . '://' . $host;
        $port = $uri->getPort();
        if ($port !== null && !(($uri->getScheme() === 'http' && $port === 80) || ($uri->getScheme() === 'https' && $port === 443))) {
            $resource .= ':' . $port;
        }

        return $this->requireCanonicalResourceUri($resource . '/mcp');
    }
}
