<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\FileSessionStore;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteInformationService;

/**
 * MCP HTTP Endpoint for remote access
 */
final readonly class McpEndpoint
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * eID entry point via __invoke method
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Get services through DI container
            $container = GeneralUtility::getContainer();
            $serverFactory = $container->get(McpServerFactory::class);
            if (!$serverFactory instanceof McpServerFactory) {
                throw new RuntimeException('MCP server factory is not available');
            }

            // Debug: Log all request details
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            $queryParams = $request->getQueryParams();

            $this->logger->debug('MCP request received', [
                'method' => $request->getMethod(),
                'headers' => $headers,
                'queryParams' => $queryParams,
            ]);

            // Check if this is an auth header test request
            if (isset($queryParams['test']) && $queryParams['test'] === 'auth') {
                return $this->handleAuthHeaderTest($request);
            }

            // Authenticate via Bearer token or query parameter
            $token = $this->extractToken($request);

            if (!$token) {
                $this->logger->warning('No token found in Authorization header or query params');
                return $this->createUnauthorizedResponse('Missing authentication token');
            }

            $this->logger->debug('Token received', ['tokenPrefix' => substr($token, 0, 20)]);

            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $tokenInfo = $oauthService->validateToken($token, $request);

            if (!$this->isValidTokenInfo($tokenInfo)) {
                $this->logger->warning('Token validation failed', ['tokenPrefix' => substr($token, 0, 20)]);
                return $this->createUnauthorizedResponse('Invalid or expired token');
            }

            $this->logger->debug('Token validation successful', ['userId' => $tokenInfo['be_user_uid']]);

            // Set up TYPO3 backend context for the authenticated user
            $this->setupBackendUserContext($tokenInfo['be_user_uid']);

            // Set current request context in SiteInformationService
            $siteInformationService = $container->get(SiteInformationService::class);
            if ($siteInformationService instanceof SiteInformationService) {
                $siteInformationService->setCurrentRequest($request);
            }

            // Create MCP server instance using the factory
            $server = $serverFactory->createServer();

            // Configure HTTP options
            $httpOptions = [
                'session_timeout' => 1800, // 30 minutes
                'max_queue_size' => 500,
                'enable_sse' => false,
                'shared_hosting' => false,
            ];

            // Create session store in TYPO3's var directory
            $sessionStore = new FileSessionStore(
                Environment::getVarPath() . '/mcp_sessions'
            );

            // Create initialization options using the factory
            $initOptions = $serverFactory->createInitializationOptions($server);

            // Create runner and adapter
            $runner = new HttpServerRunner(
                $server,
                $initOptions,
                $httpOptions,
                null,
                $sessionStore
            );

            // Handle the request and capture output
            ob_start();

            // Suppress warnings/notices from MCP SDK to prevent deprecation issues
            $oldErrorReporting = error_reporting(E_ERROR | E_PARSE);

            try {
                $adapter = new StandardPhpAdapter($runner);
                $adapter->handle();
            } finally {
                // Restore error reporting
                error_reporting($oldErrorReporting);
            }

            $output = ob_get_clean();
            if ($output === false) {
                $output = '';
            }

            // Get the status code set by the adapter
            $statusCode = http_response_code();
            if (!is_int($statusCode) || $statusCode < 100) {
                $statusCode = 200;
            }

            // Try to decode as JSON, fallback to plain text
            $decodedOutput = json_decode($output, true);
            $contentType = $decodedOutput !== null ? 'application/json' : 'text/plain';

            // Create proper stream for response
            $stream = new Stream('php://temp', 'rw');
            $stream->write($output);
            $stream->rewind();

            return new Response(
                $stream,
                $statusCode,
                ['Content-Type' => $contentType]
            );

        } catch (Throwable $e) {
            $this->logger->error('MCP request failed', ['exception' => $e]);
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson([
                'error' => 'Internal Server Error',
            ]));
            $stream->rewind();

            return new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Extract token from request (Bearer header or query parameter)
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // Try Authorization header first (preferred method)
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader !== '' && preg_match('/Bearer\s+(.+)/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        // Try HTTP_AUTHORIZATION from Apache environment (fallback for Apache)
        $serverParams = $request->getServerParams();
        $httpAuth = $serverParams['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($httpAuth) && $httpAuth !== '' && preg_match('/Bearer\s+(.+)/', $httpAuth, $matches) === 1) {
            return $matches[1];
        }

        // Fallback to query parameter (deprecated -- tokens in URLs are logged by proxies/web servers)
        $queryParams = $request->getQueryParams();
        $queryToken = $queryParams['token'] ?? null;
        if ($queryToken !== null) {
            $this->logger->warning('Token passed via query parameter is deprecated. Use the Authorization header instead.');
        }

        return is_string($queryToken) && $queryToken !== '' ? $queryToken : null;
    }

    /**
     * Create unauthorized response
     */
    private function createUnauthorizedResponse(string $message): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->encodeJson([
            'error' => 'Unauthorized',
            'message' => $message
        ]));
        $stream->rewind();

        return new Response(
            $stream,
            401,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Set up backend user context
     */
    private function setupBackendUserContext(int $userId): void
    {
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        // Load user data
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
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

            // CRITICAL: Fetch group data to populate permissions
            // This computes tables_select, tables_modify, non_exclude_fields, webmounts, etc.
            // Without this, non-admin users have no permissions computed from their groups
            $beUser->fetchGroupData();

            // Initialize language service (required for DataHandler and other core components)
            $this->initializeLanguageService($beUser);

            // Set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);

            // Set up TYPO3 Context API (following BackendUserAuthenticator pattern)
            $context = GeneralUtility::makeInstance(Context::class);
            $context->setAspect('backend.user', new UserAspect($beUser));
            $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

            $this->logger->debug('Workspace selected', ['userId' => $userId, 'workspaceId' => $workspaceId]);
        }

        // Ensure TCA is loaded using proper TYPO3 core method
        $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
        if ($tcaFactory instanceof TcaFactory) {
            $GLOBALS['TCA'] = $tcaFactory->get();
        }
    }

    /**
     * Initialize language service for the backend user
     */
    private function initializeLanguageService(BackendUserAuthentication $beUser): void
    {
        // Create language service
        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $languageService = $languageServiceFactory->createFromUserPreferences($beUser);

        // Set global language service
        $GLOBALS['LANG'] = $languageService;
    }

    /**
     * Handle auth header test request
     */
    private function handleAuthHeaderTest(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];
        $receivedAuthHeader = false;

        // Check all possible ways the Authorization header might arrive
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader)) {
            $headers['authorization'] = $authHeader;
            $receivedAuthHeader = true;
        }

        // Check server params for HTTP_AUTHORIZATION
        $serverParams = $request->getServerParams();
        if (isset($serverParams['HTTP_AUTHORIZATION'])) {
            $headers['http_authorization'] = is_string($serverParams['HTTP_AUTHORIZATION']) ? $serverParams['HTTP_AUTHORIZATION'] : '';
            $receivedAuthHeader = true;
        }

        // Also check for redirect env variable (Apache specific)
        if (isset($serverParams['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['redirect_http_authorization'] = is_string($serverParams['REDIRECT_HTTP_AUTHORIZATION']) ? $serverParams['REDIRECT_HTTP_AUTHORIZATION'] : '';
            $receivedAuthHeader = true;
        }

        $response = GeneralUtility::makeInstance(Response::class)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withStatus(200);

        $responseData = [
            'test' => 'auth',
            'headers_received' => $headers,
            'auth_header_detected' => $receivedAuthHeader,
            'server_software' => is_string($serverParams['SERVER_SOFTWARE'] ?? null) ? $serverParams['SERVER_SOFTWARE'] : 'unknown',
            'hint' => !$receivedAuthHeader ? 'Authorization header not received. See module page for solutions.' : 'Authorization header received successfully.'
        ];

        $body = GeneralUtility::makeInstance(Stream::class, 'php://temp', 'rw');
        $body->write($this->encodeJson($responseData, JSON_PRETTY_PRINT));

        return $response->withBody($body);
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
