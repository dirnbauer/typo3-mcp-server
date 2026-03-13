<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\WorkspaceContextService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module controller for MCP Server configuration
 */
final readonly class McpServerModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ToolRegistry $toolRegistry,
        private PageRenderer $pageRenderer,
        private OAuthService $oauthService,
        private WorkspaceContextService $workspaceContextService,
        private UriBuilder $uriBuilder,
    ) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        // Get current user
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new HtmlResponse('Access denied', 403);
        }

        // Get user's OAuth tokens
        $userId = (int) ($backendUser->user['uid'] ?? 0);
        /** @var list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens */
        $tokens = $this->oauthService->getUserTokens($userId);

        // Get base URL for endpoint
        $baseUrl = $this->getBaseUrl($request);

        // Generate OAuth authorization URL
        $authUrl = $this->oauthService->generateAuthorizationUrl($baseUrl, 'Claude Desktop');

        // Get available tools
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }


        // Generate mcp-remote token URL (for clients that don't support auth headers)
        $mcpRemoteUrl = $this->generateMcpRemoteUrl($baseUrl, $tokens);

        // Generate token info for n8n and manus
        $n8nTokenInfo = $this->getClientTokenInfo($tokens, 'n8n token');
        $manusTokenInfo = $this->getClientTokenInfo($tokens, 'manus token');

        // Check if any workspace exists
        $hasWorkspace = $this->hasAnyWorkspace();
        $workspaceInfo = $this->workspaceContextService->getWorkspaceInfo();

        // Detect if the server is running on localhost
        $isLocalhost = $this->isLocalhostUrl($baseUrl);

        // Generate URL to create a new workspace record (pid 0 = root level)
        $createWorkspaceUrl = (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_workspace' => [0 => 'new']],
            'returnUrl' => (string) $request->getUri(),
        ]);

        // Prepare template variables
        $templateVariables = [
            'tokens' => $tokens,
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'tools' => $tools,
            'username' => \is_string($backendUser->user['username'] ?? null) ? $backendUser->user['username'] : 'unknown',
            'userId' => $userId,
            'mcpRemoteUrl' => $mcpRemoteUrl,
            'n8nTokenInfo' => $n8nTokenInfo,
            'manusTokenInfo' => $manusTokenInfo,
            'siteName' => $this->getSiteName(),
            'hasWorkspace' => $hasWorkspace,
            'isLocalhost' => $isLocalhost,
            'createWorkspaceUrl' => $createWorkspaceUrl,
            'workspaceInfo' => $workspaceInfo,
        ];

        // Include JavaScript for copy functionality
        $this->pageRenderer->addJsFile('EXT:mcp_server/Resources/Public/JavaScript/mcp-module.js');

        // Include CSS for endpoint status indicators
        $this->pageRenderer->addCssFile('EXT:mcp_server/Resources/Public/Css/mcp-module.css');

        // Assign variables to ModuleTemplate and render
        $moduleTemplate->assignMultiple($templateVariables);
        $moduleTemplate->setTitle('MCP Server Configuration');

        return $moduleTemplate->renderResponse('McpServerModule');
    }


    /**
     * Revoke a specific token
     */
    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $rawBody = $request->getBody()->getContents();

        // Reset body stream position for further processing
        $request->getBody()->rewind();

        $parsedBody = $this->getRequestData($request->getParsedBody());

        // If parsedBody is null, try to decode JSON manually
        if ($parsedBody === [] && $rawBody !== '') {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && \is_array($jsonData)) {
                $parsedBody = $jsonData;
            }
        }

        $tokenIdValue = $parsedBody['tokenId'] ?? '0';
        $tokenId = is_numeric($tokenIdValue) ? (int) $tokenIdValue : 0;
        $userId = (int) ($backendUser->user['uid'] ?? 0);

        if ($tokenId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token ID'], 400);
        }

        try {
            $success = $this->oauthService->revokeToken($tokenId, $userId);

            if ($success) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Token revoked successfully',
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Token not found or access denied',
                ], 404);
            }
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error revoking token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke all tokens for the current user
     */
    public function revokeAllTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $userId = (int) ($backendUser->user['uid'] ?? 0);

        try {
            $revokedCount = $this->oauthService->revokeAllUserTokens($userId);

            if ($revokedCount > 0) {
                return new JsonResponse([
                    'success' => true,
                    'message' => \sprintf('Successfully revoked %d token%s', $revokedCount, $revokedCount === 1 ? '' : 's'),
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No tokens found to revoke',
                ], 404);
            }
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error revoking tokens: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getBaseUrl(ServerRequestInterface $request): string
    {
        // Try to get from TYPO3 configuration first
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $configuredBaseUrl = \is_array($confVars) && \is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['reverseProxyBaseUrl'] ?? null)
            : null;
        $baseUrl = \is_string($configuredBaseUrl) ? $configuredBaseUrl : '';

        if (empty($baseUrl)) {
            // Fallback to request-based detection
            $scheme = $request->getUri()->getScheme();
            $host = $request->getUri()->getHost();
            $port = $request->getUri()->getPort();

            $baseUrl = $scheme . '://' . $host;
            if ($port && !\in_array($port, [80, 443])) {
                $baseUrl .= ':' . $port;
            }
        }

        return rtrim($baseUrl, '/');
    }


    private function getSiteName(): string
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $siteName = \is_array($confVars) && \is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['sitename'] ?? null)
            : null;
        return \is_string($siteName) && $siteName !== '' ? $siteName : 'TYPO3 MCP Server';
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    /**
     * Get user tokens via AJAX for dynamic updates
     */
    public function getUserTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $userId = (int) ($backendUser->user['uid'] ?? 0);
            /** @var list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens */
            $tokens = $this->oauthService->getUserTokens($userId);

            // Format tokens for frontend display
            $formattedTokens = array_map(fn(array $token): array => [
                'uid' => $token['uid'],
                'client_name' => $token['client_name'],
                'created' => date('Y-m-d H:i:s', $token['crdate']),
                'expires' => date('Y-m-d H:i:s', $token['expires']),
                'last_used' => $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : 'Never',
                'token_preview' => substr((string) $token['token'], 0, 20) . '...',
            ], $tokens);

            return new JsonResponse([
                'success' => true,
                'tokens' => $formattedTokens,
            ]);

        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error retrieving tokens: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate mcp-remote URL with token parameter for clients that don't support auth headers
     */
    /**
     * @param list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens
     * @return array{baseUrl: string, hasTokens: bool, tokenUrl: string|null, description: string}
     */
    private function generateMcpRemoteUrl(string $baseUrl, array $tokens): array
    {
        $endpointUrl = $baseUrl . '/mcp';

        // Filter tokens to only include mcp-remote tokens
        $mcpRemoteTokens = array_filter($tokens, fn(array $token): bool => $token['client_name'] === 'mcp-remote token');

        return [
            'baseUrl' => $endpointUrl,
            'hasTokens' => !empty($mcpRemoteTokens),
            'tokenUrl' => !empty($mcpRemoteTokens) ? $endpointUrl . '?token=' . array_values($mcpRemoteTokens)[0]['token'] : null,
            'description' => 'For MCP clients that don\'t support Authorization headers (like mcp-remote without auth)',
        ];
    }

    /**
     * Get token info for a specific client type
     */
    /**
     * @param list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens
     * @return array{hasToken: bool, token: string|null, expires: int|null, clientName: string}
     */
    private function getClientTokenInfo(array $tokens, string $clientName): array
    {
        $clientTokens = array_filter($tokens, fn(array $token): bool => $token['client_name'] === $clientName);

        $hasToken = !empty($clientTokens);
        $tokenValues = array_values($clientTokens);
        $token = $hasToken && isset($tokenValues[0]) ? $tokenValues[0] : null;

        return [
            'hasToken' => $hasToken,
            'token' => $token['token'] ?? null,
            'expires' => $token['expires'] ?? null,
            'clientName' => $clientName,
        ];
    }

    /**
     * Check if any TYPO3 workspace exists
     */
    private function hasAnyWorkspace(): bool
    {
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            $count = $queryBuilder
                ->count('uid')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchOne();
            return is_numeric($count) && (int) $count > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if the base URL resolves to a private/non-routable network address.
     * Catches localhost, DDEV domains (*.ddev.site), Docker networks, etc.
     * Checks both IPv4 (A) and IPv6 (AAAA) records to avoid false positives
     * on IPv6-only hosts.
     */
    private function isLocalhostUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        // Quick check for obvious literals
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Resolve both A and AAAA records
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            // Cannot resolve at all — don't assume private, could be a DNS issue
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                // At least one public IP → not localhost-only
                return false;
            }
        }

        // All resolved IPs are private/reserved
        return true;
    }

    /**
     * Resolve a hostname to all its IPv4 and IPv6 addresses.
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        // IPv4 A records
        $ipv4 = gethostbynamel($host);
        if ($ipv4 !== false) {
            $ips = $ipv4;
        }

        // IPv6 AAAA records
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Create an access token for MCP clients via AJAX
     * Supports different client types (mcp-remote, n8n, manus)
     */
    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $userId = (int) ($backendUser->user['uid'] ?? 0);

            // Get client type from POST body (default to mcp-remote for backward compatibility)
            $rawBody = $request->getBody()->getContents();
            $request->getBody()->rewind();
            $parsedBody = $request->getParsedBody();

            if ($parsedBody === null && !empty($rawBody)) {
                $jsonData = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE && \is_array($jsonData)) {
                    $parsedBody = $jsonData;
                }
            }

            $requestData = $this->getRequestData($parsedBody);
            $clientType = $requestData['clientType'] ?? 'mcp-remote token';

            // Validate client type
            $allowedClientTypes = ['mcp-remote token', 'n8n token', 'manus token'];
            if (!\in_array($clientType, $allowedClientTypes, true)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid client type',
                ], 400);
            }

            // Check if user already has a token for this client type
            $existingTokens = $this->oauthService->getUserTokens($userId);
            $tokenExists = false;
            foreach ($existingTokens as $token) {
                if ($token['client_name'] === $clientType) {
                    $tokenExists = true;
                    break;
                }
            }

            if ($tokenExists) {
                return new JsonResponse([
                    'success' => false,
                    'message' => \sprintf('You already have a %s. Please revoke it first if you want to create a new one.', $clientType),
                ], 400);
            }

            // Create new token for the specified client type
            $token = $this->oauthService->createDirectAccessToken($userId, $clientType, $request);

            return new JsonResponse([
                'success' => true,
                'message' => \sprintf('%s created successfully', $clientType),
                'token' => $token,
            ]);

        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param mixed $source
     * @return array<string, string>
     */
    private function getRequestData(mixed $source): array
    {
        if (!\is_array($source)) {
            return [];
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

}
