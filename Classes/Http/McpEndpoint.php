<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
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
use TYPO3\CMS\Core\Database\Connection;
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

    private const MAX_MCP_REQUEST_BODY_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private LoggerInterface $logger,
        private OAuthService $oauthService,
        private ConnectionPool $connectionPool,
        private WorkspaceContextService $workspaceContextService,
        private LanguageServiceFactory $languageServiceFactory,
        private ExtensionConfiguration $extensionConfiguration,
        private SiteBaseUrlResolver $baseUrlResolver,
    ) {}

    /**
     * eID entry point via __invoke method
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $corsRejection = $this->rejectDisallowedCorsRequest($request);
            if ($corsRejection instanceof ResponseInterface) {
                return $corsRejection;
            }

            // Preflight for browser-based MCP clients (OAuth redirect / streamable HTTP over HTTPS).
            // Without this, OPTIONS hits token extraction and returns 401; POST success had no CORS headers.
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflightRequest($request);
            }

            $container = GeneralUtility::getContainer();
            $serverFactory = $container->get(McpServerFactory::class);
            if (!$serverFactory instanceof McpServerFactory) {
                throw new \RuntimeException('MCP server factory is not available');
            }

            $queryParams = $request->getQueryParams();
            $this->logger->debug('MCP request received', [
                'method' => $request->getMethod(),
                // Never log getRequestTarget(): it includes the raw query
                // string and can expose rejected legacy bearer parameters.
                'path' => $request->getUri()->getPath(),
                'headerNames' => array_keys($request->getHeaders()),
                'headers' => McpHttpLogRedactor::redactHeadersForLog($request->getHeaders()),
                'queryParams' => McpHttpLogRedactor::redactQueryParamsForLog($queryParams),
            ]);

            if (isset($queryParams['test']) && $queryParams['test'] === 'auth') {
                return $this->handleAuthHeaderTest($request);
            }

            $token = $this->extractToken($request);

            if (!$token) {
                $this->logger->warning('No token found in Authorization header');
                return $this->createUnauthorizedResponse($request, 'Missing authentication token');
            }

            $this->logger->debug('MCP request authenticated via bearer token');

            $tokenInfo = $this->oauthService->validateToken(
                $token,
                $request,
                $this->oauthService->canonicalMcpResourceFromRequest($request),
                OAuthService::DEFAULT_SCOPE,
            );

            if (!$this->isValidTokenInfo($tokenInfo)) {
                $this->logger->warning('Token validation failed for MCP request');
                return $this->createUnauthorizedResponse($request, 'Invalid or expired token');
            }

            $this->logger->debug('Token validation successful', ['userId' => $tokenInfo['be_user_uid']]);

            if (!$this->setupBackendUserContext($tokenInfo['be_user_uid'])) {
                $this->logger->warning('Token backend user is missing or inactive');
                return $this->createUnauthorizedResponse($request, 'Invalid or expired token');
            }

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
                'allowed_origins' => $this->getAllowedCorsHostnames($request),
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

            $httpRequest = $this->createSdkHttpRequest($request);
            $httpResponse = $runner->handleRequest($httpRequest);

            $body = $httpResponse->getBody() ?? '';
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();

            $headers = [];
            $hasContentType = false;
            foreach ($httpResponse->getHeaders() as $name => $value) {
                $headers[$name] = $value;
                if (strcasecmp($name, 'Content-Type') === 0) {
                    $hasContentType = true;
                }
            }
            if (!$hasContentType) {
                $decodedBody = json_decode($body, true);
                $headers['Content-Type'] = $decodedBody !== null ? 'application/json' : 'text/plain';
            }

            $response = new Response(
                $stream,
                $httpResponse->getStatusCode(),
                $headers,
            );

            return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
        } catch (\LengthException $e) {
            $this->logger->warning('MCP request payload rejected', ['reason' => $e->getMessage()]);
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson(['error' => 'Payload Too Large']));
            $stream->rewind();

            return $this->addSecurityHeaders($this->addCorsHeaders(new Response(
                $stream,
                413,
                ['Content-Type' => 'application/json'],
            ), $request));
        } catch (\Throwable $e) {
            $this->logger->error('MCP request failed', ['exception' => $e]);
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson([
                'error' => 'Internal Server Error',
            ]));
            $stream->rewind();

            return $this->addSecurityHeaders($this->addCorsHeaders(new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json'],
            ), $request));
        }
    }

    private function isAuthHeaderDiagnosticEnabled(): bool
    {
        return $this->readExtensionBool('enableMcpAuthHeaderDiagnostic', false);
    }

    /**
     * Adapt TYPO3's authoritative PSR-7 request to the SDK transport model.
     * Reading PHP globals here would lose middleware-normalized headers and
     * makes concurrent/request-factory tests observe the wrong body.
     */
    private function createSdkHttpRequest(ServerRequestInterface $request): HttpMessage
    {
        $httpRequest = new HttpMessage($this->readBoundedRequestBody($request));
        $httpRequest
            ->setMethod($request->getMethod())
            ->setUri($request->getRequestTarget());

        foreach ($request->getHeaders() as $name => $values) {
            $httpRequest->setHeader($name, implode(', ', $values));
        }

        $queryParams = [];
        foreach ($request->getQueryParams() as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))) {
                continue;
            }
            $queryParams[$name] = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        }
        $httpRequest->setQueryParams($queryParams);

        return $httpRequest;
    }

    private function readBoundedRequestBody(ServerRequestInterface $request): string
    {
        $contentLength = trim($request->getHeaderLine('Content-Length'));
        if ($contentLength !== ''
            && ctype_digit($contentLength)
            && (int)$contentLength > self::MAX_MCP_REQUEST_BODY_BYTES
        ) {
            throw new \LengthException('Content-Length exceeds the MCP request limit.');
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $contents = '';
        while (!$body->eof() && strlen($contents) <= self::MAX_MCP_REQUEST_BODY_BYTES) {
            $remaining = (self::MAX_MCP_REQUEST_BODY_BYTES + 1) - strlen($contents);
            $chunk = $body->read(min(65536, $remaining));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }
        if (strlen($contents) > self::MAX_MCP_REQUEST_BODY_BYTES || !$body->eof()) {
            throw new \LengthException('MCP request body exceeds the configured limit.');
        }

        return $contents;
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
     * Extract a bearer token exclusively from the Authorization header.
     * Tokens in URIs are forbidden by the MCP authorization specification.
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        $token = $this->extractBearerToken($request->getHeaderLine('Authorization'));
        if ($token !== null) {
            return $token;
        }

        $serverParams = $request->getServerParams();
        $httpAuth = $serverParams['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($httpAuth)) {
            $token = $this->extractBearerToken($httpAuth);
            if ($token !== null) {
                return $token;
            }
        }

        // Apache mod_rewrite / mod_auth may expose the header as REDIRECT_HTTP_AUTHORIZATION
        $redirectAuth = $serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($redirectAuth)) {
            return $this->extractBearerToken($redirectAuth);
        }

        return null;
    }

    private function extractBearerToken(string $authorizationHeader): ?string
    {
        if (preg_match('/^Bearer[ \t]+([A-Za-z0-9\-._~+\/=]+)$/i', trim($authorizationHeader), $matches) !== 1) {
            return null;
        }

        return $matches[1];
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
        $resourceMetadataUrl = $this->baseUrlResolver->resolveProtectedResourceMetadataUrl($request);

        $response = new Response(
            $stream,
            401,
            [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="' . $resourceMetadataUrl . '", scope="' . OAuthService::DEFAULT_SCOPE . '"',
            ],
        );

        return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
    }

    private function setupBackendUserContext(int $userId): bool
    {
        unset($GLOBALS['BE_USER']);
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $connection = $this->connectionPool
            ->getConnectionForTable('be_users');

        $now = time();
        $queryBuilder = $connection->createQueryBuilder();
        $userData = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
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
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($userData)) {
            return false;
        }

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

        $workspaceId = $this->workspaceContextService->switchToReadWorkspace($beUser);

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('backend.user', new UserAspect($beUser));
        $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

        $this->logger->debug('Workspace selected', ['userId' => $userId, 'workspaceId' => $workspaceId]);

        $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
        if ($tcaFactory instanceof TcaFactory) {
            $GLOBALS['TCA'] = $tcaFactory->get();
        }

        return true;
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
        $defaultUc = $this->getBackendDefaultUserConfiguration();
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
            return $this->normalizeStringKeyedArray($storedUc);
        }
        if (!is_string($storedUc) || $storedUc === '') {
            return [];
        }

        try {
            // TYPO3 stores backend-user UC as serialized arrays; object hydration is disabled.
            // nosemgrep: php.lang.security.unserialize-use.unserialize-use
            $decoded = unserialize($storedUc, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        return $this->normalizeStringKeyedArray($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function getBackendDefaultUserConfiguration(): array
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($typo3Configuration)) {
            return [];
        }

        $backendConfiguration = $typo3Configuration['BE'] ?? null;
        if (!is_array($backendConfiguration)) {
            return [];
        }

        return $this->normalizeStringKeyedArray($backendConfiguration['defaultUC'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
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

            $response = GeneralUtility::makeInstance(Response::class)
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody($stream);

            return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
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

        return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
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
