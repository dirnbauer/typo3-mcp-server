<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\WorkspaceContextService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

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
        private ConnectionPool $connectionPool,
        private FormProtectionFactory $formProtectionFactory,
    ) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new HtmlResponse($this->translate('accessDenied', fallback: 'Access denied'), 403);
        }

        $userId = (int)($backendUser->user['uid'] ?? 0);
        /** @var list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens */
        $tokens = $this->oauthService->getUserTokens($userId);

        $baseUrl = $this->getBaseUrl($request);
        $endpointUrl = $baseUrl . '/mcp';
        $siteName = $this->getSiteName();
        $authUrl = $this->oauthService->generateAuthorizationUrl($baseUrl, 'Claude Desktop');

        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }

        $mcpRemoteUrl = $this->generateMcpRemoteUrl($baseUrl, $tokens);
        $n8nTokenInfo = $this->getClientTokenInfo($tokens, 'n8n token');
        $manusTokenInfo = $this->getClientTokenInfo($tokens, 'manus token');
        $hasWorkspace = $this->hasAnyWorkspace();
        $workspaceInfo = $this->workspaceContextService->getWorkspaceInfo();
        $isLocalhost = $this->isLocalhostUrl($baseUrl);

        $createWorkspaceUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_workspace' => [0 => 'new']],
            'returnUrl' => (string)$request->getUri(),
        ]);

        $templateVariables = [
            'tokens' => $tokens,
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'endpointUrl' => $endpointUrl,
            'cursorInstallUrl' => $this->buildCursorInstallUrl($siteName, $endpointUrl),
            'tools' => $tools,
            'username' => is_string($backendUser->user['username'] ?? null) ? $backendUser->user['username'] : 'unknown',
            'userId' => $userId,
            'mcpRemoteUrl' => $mcpRemoteUrl,
            'n8nTokenInfo' => $n8nTokenInfo,
            'manusTokenInfo' => $manusTokenInfo,
            'siteName' => $siteName,
            'hasWorkspace' => $hasWorkspace,
            'isLocalhost' => $isLocalhost,
            'createWorkspaceUrl' => $createWorkspaceUrl,
            'workspaceInfo' => $workspaceInfo,
            'csrfToken' => $this->formProtectionFactory
                ->createForType('backend')
                ->generateToken('mcpserver', 'tokenManagement'),
        ];

        $this->pageRenderer->addCssFile('EXT:mcp_server/Resources/Public/Css/mcp-module.css');

        $moduleTemplate->assignMultiple($templateVariables);
        $moduleTemplate->setTitle($this->translate('title', fallback: 'MCP Server Configuration'));

        $this->pageRenderer->addInlineLanguageLabelFile('EXT:mcp_server/Resources/Private/Language/locallang_mod.xlf');

        return $moduleTemplate->renderResponse('McpServerModule');
    }

    /**
     * Revoke a specific token
     */
    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('accessDenied')], 403);
        }

        $rawBody = $request->getBody()->getContents();
        $request->getBody()->rewind();

        $parsedBody = $this->getRequestData($request->getParsedBody());

        if ($parsedBody === [] && $rawBody !== '') {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $parsedBody = $jsonData;
            }
        }

        if (!$this->validateCsrfToken($parsedBody)) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('csrfFailed')], 403);
        }

        $tokenIdValue = $parsedBody['tokenId'] ?? '0';
        $tokenId = is_numeric($tokenIdValue) ? (int)$tokenIdValue : 0;
        $userId = (int)($backendUser->user['uid'] ?? 0);

        if ($tokenId <= 0) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('tokens.invalidId')], 400);
        }

        try {
            $success = $this->oauthService->revokeToken($tokenId, $userId);

            if ($success) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $this->translate('tokens.revokedSuccess'),
                ]);
            }
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.notFoundOrDenied'),
            ], 404);

        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.revokeError'),
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
            return new JsonResponse(['success' => false, 'message' => $this->translate('accessDenied')], 403);
        }

        $rawBody = $request->getBody()->getContents();
        $request->getBody()->rewind();
        $parsedBody = $this->getRequestData($request->getParsedBody());
        if ($parsedBody === [] && $rawBody !== '') {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                $parsedBody = $jsonData;
            }
        }
        if (!$this->validateCsrfToken($parsedBody)) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('csrfFailed')], 403);
        }

        $userId = (int)($backendUser->user['uid'] ?? 0);

        try {
            $revokedCount = $this->oauthService->revokeAllUserTokens($userId);

            if ($revokedCount > 0) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $this->translate('tokens.revokedCount', ['count' => $revokedCount]),
                ]);
            }
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.noTokensToRevoke'),
            ], 404);

        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.revokeAllError'),
            ], 500);
        }
    }

    private function getBaseUrl(ServerRequestInterface $request): string
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $configuredBaseUrl = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['reverseProxyBaseUrl'] ?? null)
            : null;
        $baseUrl = is_string($configuredBaseUrl) ? $configuredBaseUrl : '';

        if (empty($baseUrl)) {
            $scheme = $request->getUri()->getScheme();
            $host = $request->getUri()->getHost();
            $port = $request->getUri()->getPort();

            $baseUrl = $scheme . '://' . $host;
            if ($port && !in_array($port, [80, 443])) {
                $baseUrl .= ':' . $port;
            }
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Cursor "Install in Cursor" deeplink.
     *
     * - Config is the single-server object that becomes one entry in mcp.json (HTTP: `url` only).
     * - Use RFC 4648 URL-safe base64 (`+`/`/` → `-`/`_`) so the value is safe in a query string.
     * - Do **not** rawurlencode the config: Cursor and the official tooling pass base64 with
     *   literal `=` padding; `%3D` breaks some builds' decoders.
     *
     * @see https://docs.cursor.com/deeplinks
     */
    private function buildCursorInstallUrl(string $serverName, string $endpointUrl): string
    {
        $config = ['url' => $endpointUrl];
        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        $json = is_string($json) ? $json : '{}';
        $configParam = strtr(base64_encode($json), '+/', '-_');

        return 'cursor://anysphere.cursor-deeplink/mcp/install?name='
            . rawurlencode($serverName)
            . '&config='
            . $configParam;
    }

    private function getSiteName(): string
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $siteName = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['sitename'] ?? null)
            : null;
        return is_string($siteName) && $siteName !== '' ? $siteName : 'TYPO3 MCP Server';
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function getLanguageService(): LanguageService
    {
        /** @var LanguageService $languageService */
        $languageService = $GLOBALS['LANG'];
        return $languageService;
    }

    /**
     * @param array<string, string|int> $arguments
     */
    private function translate(string $id, array $arguments = [], string $fallback = ''): string
    {
        return (string)($this->getLanguageService()->translate($id, 'mcp_server.mod', $arguments) ?? $fallback);
    }

    /**
     * Get user tokens via AJAX for dynamic updates
     */
    public function getUserTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('accessDenied')], 403);
        }

        $neverUsed = $this->translate('tokens.neverUsed', fallback: 'Never');

        try {
            $userId = (int)($backendUser->user['uid'] ?? 0);
            /** @var list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens */
            $tokens = $this->oauthService->getUserTokens($userId);

            $formattedTokens = array_map(fn(array $token): array => [
                'uid' => $token['uid'],
                'client_name' => $token['client_name'],
                'created' => date('Y-m-d H:i:s', $token['crdate']),
                'expires' => date('Y-m-d H:i:s', $token['expires']),
                'last_used' => $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : $neverUsed,
                'token_preview' => substr((string)$token['token'], 0, 20) . '...',
            ], $tokens);

            return new JsonResponse([
                'success' => true,
                'tokens' => $formattedTokens,
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.loadError'),
            ], 500);
        }
    }

    /**
     * @param list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens
     * @return array{baseUrl: string, hasTokens: bool, tokenUrl: string|null, description: string}
     */
    private function generateMcpRemoteUrl(string $baseUrl, array $tokens): array
    {
        $endpointUrl = $baseUrl . '/mcp';
        $mcpRemoteTokens = array_filter($tokens, fn(array $token): bool => $token['client_name'] === 'mcp-remote token');

        return [
            'baseUrl' => $endpointUrl,
            'hasTokens' => !empty($mcpRemoteTokens),
            'tokenUrl' => null,
            'description' => $this->translate('tokens.secureHashDescription'),
        ];
    }

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
            'token' => null,
            'expires' => $token['expires'] ?? null,
            'clientName' => $clientName,
        ];
    }

    private function hasAnyWorkspace(): bool
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
            $count = $queryBuilder
                ->count('uid')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchOne();
            return is_numeric($count) && (int)$count > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Checks both IPv4 (A) and IPv6 (AAAA) records to avoid false positives on IPv6-only hosts.
     */
    private function isLocalhostUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        $ipv4 = gethostbynamel($host);
        if ($ipv4 !== false) {
            $ips = $ipv4;
        }

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
     * Create an access token for MCP clients via AJAX.
     * Supports different client types (mcp-remote, n8n, manus).
     */
    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('accessDenied')], 403);
        }

        try {
            $userId = (int)($backendUser->user['uid'] ?? 0);

            $rawBody = $request->getBody()->getContents();
            $request->getBody()->rewind();
            $parsedBody = $request->getParsedBody();

            if ($parsedBody === null && !empty($rawBody)) {
                $jsonData = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    $parsedBody = $jsonData;
                }
            }

            $requestData = $this->getRequestData($parsedBody);

            if (!$this->validateCsrfToken($requestData)) {
                return new JsonResponse(['success' => false, 'message' => $this->translate('csrfFailed')], 403);
            }

            $clientType = $requestData['clientType'] ?? 'mcp-remote token';

            $allowedClientTypes = ['mcp-remote token', 'n8n token', 'manus token'];
            if (!in_array($clientType, $allowedClientTypes, true)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->translate('tokens.invalidClientType'),
                ], 400);
            }

            $existingTokens = $this->oauthService->getUserTokens($userId);
            foreach ($existingTokens as $token) {
                if ($token['client_name'] === $clientType) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $this->translate('tokens.alreadyExists', ['clientType' => $clientType]),
                    ], 400);
                }
            }

            $token = $this->oauthService->createDirectAccessToken($userId, $clientType, $request);

            return new JsonResponse([
                'success' => true,
                'message' => $this->translate('tokens.createdSuccessfully', ['clientType' => $clientType]),
                'token' => $token,
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.createError'),
            ], 500);
        }
    }

    /**
     * @param mixed $source
     * @return array<string, string>
     */
    private function getRequestData(mixed $source): array
    {
        if (!is_array($source)) {
            return [];
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function validateCsrfToken(array $requestData): bool
    {
        $token = $requestData['csrfToken'] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }

        return $this->formProtectionFactory
            ->createForType('backend')
            ->validateToken($token, 'mcpserver', 'tokenManagement');
    }
}
