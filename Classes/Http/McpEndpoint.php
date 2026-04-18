<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP HTTP Endpoint for remote access
 */
final readonly class McpEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private LoggerInterface $logger,
        private OAuthService $oauthService,
        private ConnectionPool $connectionPool,
        private WorkspaceContextService $workspaceContextService,
        private LanguageServiceFactory $languageServiceFactory,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * eID entry point via __invoke method
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Preflight for browser-based MCP clients (OAuth redirect / streamable HTTP over HTTPS).
            // Without this, OPTIONS hits token extraction and returns 401; POST success had no CORS headers.
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflightRequest();
            }

            $container = GeneralUtility::getContainer();
            $serverFactory = $container->get(McpServerFactory::class);
            if (!$serverFactory instanceof McpServerFactory) {
                throw new \RuntimeException('MCP server factory is not available');
            }

            $queryParams = $request->getQueryParams();
            $this->logger->debug('MCP request received', [
                'method' => $request->getMethod(),
                'requestTarget' => $request->getRequestTarget(),
                'headerNames' => array_keys($request->getHeaders()),
                'headers' => McpHttpLogRedactor::redactHeadersForLog($request->getHeaders()),
                'queryParams' => McpHttpLogRedactor::redactQueryParamsForLog($queryParams),
            ]);

            if (isset($queryParams['test']) && $queryParams['test'] === 'auth') {
                return $this->handleAuthHeaderTest($request);
            }

            $token = $this->extractToken($request);

            if (!$token) {
                $this->logger->warning('No token found in Authorization header (or query parameter when explicitly allowed)');
                return $this->createUnauthorizedResponse($request, 'Missing authentication token');
            }

            $this->logger->debug('MCP request authenticated via bearer token');

            $tokenInfo = $this->oauthService->validateToken($token, $request);

            if (!$this->isValidTokenInfo($tokenInfo)) {
                $this->logger->warning('Token validation failed for MCP request');
                return $this->createUnauthorizedResponse($request, 'Invalid or expired token');
            }

            $this->logger->debug('Token validation successful', ['userId' => $tokenInfo['be_user_uid']]);

            $this->setupBackendUserContext($tokenInfo['be_user_uid']);

            $siteInformationService = $container->get(SiteInformationService::class);
            if ($siteInformationService instanceof SiteInformationService) {
                $siteInformationService->setCurrentRequest($request);
            }

            $server = $serverFactory->createServer();

            $httpOptions = [
                'session_timeout' => 1800,
                'max_queue_size' => 500,
                'enable_sse' => false,
                'shared_hosting' => false,
            ];

            $sessionStore = new FileSessionStore(
                Environment::getVarPath() . '/mcp_sessions',
            );

            $initOptions = $serverFactory->createInitializationOptions($server);

            $runner = new HttpServerRunner(
                $server,
                $initOptions,
                $httpOptions,
                $this->logger,
                $sessionStore,
            );

            $httpRequest = HttpMessage::fromGlobals();
            $httpResponse = $runner->handleRequest($httpRequest);

            $body = $httpResponse->getBody() ?? '';
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();

            $headers = [];
            foreach ($httpResponse->getHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
            if (!isset($headers['Content-Type'])) {
                $decodedBody = json_decode($body, true);
                $headers['Content-Type'] = $decodedBody !== null ? 'application/json' : 'text/plain';
            }

            $response = new Response(
                $stream,
                $httpResponse->getStatusCode(),
                $headers,
            );

            return $this->addCorsHeaders($response);
        } catch (\Throwable $e) {
            $this->logger->error('MCP request failed', ['exception' => $e]);
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson([
                'error' => 'Internal Server Error',
            ]));
            $stream->rewind();

            return $this->addCorsHeaders(new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json'],
            ));
        }
    }

    private function isQueryTokenAllowed(): bool
    {
        return $this->readExtensionBool('allowMcpTokenInQueryString', false);
    }

    private function isAuthHeaderDiagnosticEnabled(): bool
    {
        return $this->readExtensionBool('enableMcpAuthHeaderDiagnostic', true);
    }

    private function readExtensionBool(string $key, bool $defaultIfMissing): bool
    {
        try {
            $configuration = $this->extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return $defaultIfMissing;
        }

        if (!is_array($configuration) || !array_key_exists($key, $configuration)) {
            return $defaultIfMissing;
        }

        $value = $configuration[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
        }

        return (bool)$value;
    }

    /**
     * Extract token from Authorization header, or from query string only when explicitly allowed in extension configuration.
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader !== '' && preg_match('/Bearer\s+(.+)/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $serverParams = $request->getServerParams();
        $httpAuth = $serverParams['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($httpAuth) && $httpAuth !== '' && preg_match('/Bearer\s+(.+)/', $httpAuth, $matches) === 1) {
            return $matches[1];
        }

        // Apache mod_rewrite / mod_auth may expose the header as REDIRECT_HTTP_AUTHORIZATION
        $redirectAuth = $serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($redirectAuth) && $redirectAuth !== '' && preg_match('/Bearer\s+(.+)/', $redirectAuth, $matches) === 1) {
            return $matches[1];
        }

        if (!$this->isQueryTokenAllowed()) {
            return null;
        }

        $queryParams = $request->getQueryParams();
        $queryToken = $queryParams['token'] ?? null;
        if ($queryToken !== null) {
            $this->logger->notice('MCP token accepted from query parameter because allowMcpTokenInQueryString is enabled in extension settings.');
        }

        return is_string($queryToken) && $queryToken !== '' ? $queryToken : null;
    }

    private function createUnauthorizedResponse(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->encodeJson([
            'error' => 'Unauthorized',
            'message' => $message,
        ]));
        $stream->rewind();

        // RFC 9728: resource_metadata URL must match a served protected-resource metadata document (see middleware).
        $resourceMetadataUrl = $this->buildBaseUrl($request) . '/.well-known/oauth-protected-resource/mcp';

        $response = new Response(
            $stream,
            401,
            [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . $resourceMetadataUrl . '"',
            ],
        );

        return $this->addCorsHeaders($response);
    }

    private function buildBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $baseUrl .= ':' . $port;
        }

        return $baseUrl;
    }

    private function setupBackendUserContext(int $userId): void
    {
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $connection = $this->connectionPool
            ->getConnectionForTable('be_users');

        $queryBuilder = $connection->createQueryBuilder();
        $userData = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($userData)) {
            $beUser->user = $userData;
            $GLOBALS['BE_USER'] = $beUser;

            // CRITICAL: Initialize an (anonymous) user session.
            // Normal TYPO3 requests go through BackendUserAuthenticator middleware which wires
            // up a real UserSession. Token auth bypasses that, so DataHandler write paths
            // that touch $beUser->setAndSaveSessionData() (FlashMessageQueue, BackendFormProtection)
            // crash with "Call to a member function set() on null" on UPDATE operations.
            // An anonymous in-memory session is discarded at request end — sufficient for stateless MCP.
            $beUser->initializeUserSessionManager();

            // CRITICAL: Fetch group data to populate permissions
            // This computes tables_select, tables_modify, non_exclude_fields, webmounts, etc.
            // Without this, non-admin users have no permissions computed from their groups
            $beUser->fetchGroupData();
            $this->hydrateUserConfiguration($beUser, $userData);

            $this->initializeLanguageService($beUser);

            $workspaceId = $this->workspaceContextService->switchToOptimalWorkspace($beUser);

            $context = GeneralUtility::makeInstance(Context::class);
            $context->setAspect('backend.user', new UserAspect($beUser));
            $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

            $this->logger->debug('Workspace selected', ['userId' => $userId, 'workspaceId' => $workspaceId]);
        }

        $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
        if ($tcaFactory instanceof TcaFactory) {
            $GLOBALS['TCA'] = $tcaFactory->get();
        }
    }

    /**
     * Mirror TYPO3's backendSetUC() initialization for synthetic token-authenticated users,
     * but keep it in-memory so MCP requests do not overwrite persisted backend preferences.
     *
     * @param array<string, mixed> $userData
     */
    private function hydrateUserConfiguration(BackendUserAuthentication $beUser, array $userData): void
    {
        $storedUc = $this->decodeStoredUserConfiguration($userData['uc'] ?? null);
        $defaultUc = is_array($GLOBALS['TYPO3_CONF_VARS']['BE']['defaultUC'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultUC']
            : [];
        $tsConfigDefaults = GeneralUtility::removeDotsFromTS((array)($beUser->getTSConfig()['setup.']['default.'] ?? []));

        $beUser->uc = array_merge(
            $beUser->uc_default,
            $defaultUc,
            $tsConfigDefaults,
            $storedUc,
        );
        $beUser->overrideUC();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredUserConfiguration(mixed $storedUc): array
    {
        if (is_array($storedUc)) {
            return $storedUc;
        }
        if (!is_string($storedUc) || $storedUc === '') {
            return [];
        }

        try {
            $decoded = unserialize($storedUc, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function initializeLanguageService(BackendUserAuthentication $beUser): void
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($beUser);
        $GLOBALS['LANG'] = $languageService;
    }

    /**
     * Lightweight check whether Authorization reached PHP. Disabled via extension setting on hardened sites.
     * Does not expose server software or other fingerprinting details.
     */
    private function handleAuthHeaderTest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAuthHeaderDiagnosticEnabled()) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson([
                'error' => 'forbidden',
                'message' => 'Auth header diagnostic is disabled (see extension setting enableMcpAuthHeaderDiagnostic).',
            ]));
            $stream->rewind();

            return GeneralUtility::makeInstance(Response::class)
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody($stream);
        }

        $receivedAuthHeader = false;

        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader !== '') {
            $receivedAuthHeader = true;
        }

        $serverParams = $request->getServerParams();
        if (isset($serverParams['HTTP_AUTHORIZATION'])) {
            $receivedAuthHeader = true;
        }

        if (isset($serverParams['REDIRECT_HTTP_AUTHORIZATION'])) {
            $receivedAuthHeader = true;
        }

        $responseData = [
            'test' => 'auth',
            'auth_header_detected' => $receivedAuthHeader,
            'headers_received' => [
                'authorization' => $receivedAuthHeader,
            ],
            'hint' => !$receivedAuthHeader
                ? 'Authorization header not received. See backend MCP module for server configuration hints.'
                : 'Authorization header received successfully.',
        ];

        $body = GeneralUtility::makeInstance(Stream::class, 'php://temp', 'rw');
        $body->write($this->encodeJson($responseData, JSON_PRETTY_PRINT));

        $response = GeneralUtility::makeInstance(Response::class)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200)
            ->withBody($body);

        return $this->addCorsHeaders($response);
    }

    /**
     * @param array<string, mixed>|null $tokenInfo
     * @phpstan-assert-if-true array{be_user_uid: int, client_name: string, token_uid: int} $tokenInfo
     */
    private function isValidTokenInfo(?array $tokenInfo): bool
    {
        return is_array($tokenInfo)
            && isset($tokenInfo['be_user_uid'], $tokenInfo['client_name'], $tokenInfo['token_uid'])
            && is_int($tokenInfo['be_user_uid'])
            && is_string($tokenInfo['client_name'])
            && is_int($tokenInfo['token_uid']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data, int $flags = 0): string
    {
        $json = json_encode($data, $flags);
        return is_string($json) ? $json : '{}';
    }

}
