<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Hn\McpServer\Http\AjaxRequestBodyParser;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\McpClientConfigBuilder;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\McpDiagnosticsPanelRenderer;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
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
        private McpConnectionDiagnosticService $connectionDiagnosticService,
        private SiteBaseUrlResolver $baseUrlResolver,
        private AjaxRequestBodyParser $ajaxRequestBodyParser,
        private McpClientConfigBuilder $clientConfigBuilder,
        private McpDiagnosticsPanelRenderer $diagnosticsPanelRenderer,
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
        $neverUsed = $this->translate('tokens.neverUsed', fallback: 'Never');

        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);
        $endpointUrl = $baseUrl . '/mcp';
        $siteName = $this->clientConfigBuilder->getSiteName();
        $authUrl = $this->oauthService->generateAuthorizationUrl($baseUrl, 'Claude Desktop');

        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }

        $hasWorkspace = $this->hasAnyWorkspace();
        $localStdioConfig = $this->clientConfigBuilder->buildLocalStdioConfig();
        $isLocalhost = $this->isLocalhostUrl($baseUrl);
        $diagnostics = $this->collectTranslatedDiagnostics($request, count($tokens));
        $workspaceInfo = $this->workspaceContextService->getWorkspaceInfo();

        $createWorkspaceUrl = $this->buildCreateWorkspaceUrl($request);

        $templateVariables = [
            'tokens' => $this->formatTokensForView($tokens, $neverUsed),
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'endpointUrl' => $endpointUrl,
            'cursorInstallUrl' => $this->clientConfigBuilder->buildCursorInstallUrl($siteName, $localStdioConfig),
            'localStdioConfigJson' => $this->clientConfigBuilder->buildMcpServersConfigJson($siteName, $localStdioConfig),
            'tools' => $tools,
            'username' => is_string($backendUser->user['username'] ?? null) ? $backendUser->user['username'] : 'unknown',
            'userId' => $userId,
            'siteName' => $siteName,
            'codexConfigToml' => $this->clientConfigBuilder->buildCodexTomlConfig($siteName, $localStdioConfig),
            'diagnostics' => $diagnostics,
            'mcpEndpointUrl' => $endpointUrl,
            'oauthDiscoveryUrl' => $baseUrl . '/.well-known/oauth-authorization-server',
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

        $parsedBody = $this->ajaxRequestBodyParser->parseStringFields($request);

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

        $parsedBody = $this->ajaxRequestBodyParser->parseStringFields($request);
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

    /**
     * @return array{
     *   overallStatus: string,
     *   checks: list<array{
     *     id: string,
     *     status: string,
     *     label: string,
     *     message: string,
     *     howToCheck: string,
     *     fixHint: string
     *   }>
     * }
     */
    private function collectTranslatedDiagnostics(ServerRequestInterface $request, int $userTokenCount): array
    {
        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);

        return $this->translateDiagnostics($this->connectionDiagnosticService->runChecks(
            $baseUrl,
            $this->hasAnyWorkspace(),
            count($this->toolRegistry->getTools()),
            $userTokenCount,
            $this->isLocalhostUrl($baseUrl),
            $this->clientConfigBuilder->buildLocalStdioConfig(),
        ));
    }

    private function buildCreateWorkspaceUrl(ServerRequestInterface $request): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_workspace' => [0 => 'new']],
            'returnUrl' => (string)$request->getUri(),
        ]);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function resolveBackendUserId(BackendUserAuthentication $backendUser): int
    {
        $uid = $backendUser->user['uid'] ?? 0;

        return is_numeric($uid) ? (int)$uid : 0;
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
     * Re-run connection diagnostics (server-side checks, no browser CORS).
     */
    public function runDiagnosticsAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('accessDenied')], 403);
        }

        try {
            $userId = $this->resolveBackendUserId($backendUser);
            $tokens = $this->oauthService->getUserTokens($userId);
            $diagnostics = $this->collectTranslatedDiagnostics($request, count($tokens));

            return new JsonResponse([
                'success' => true,
                'diagnosticsHtml' => $this->diagnosticsPanelRenderer->render(
                    $diagnostics,
                    $this->buildCreateWorkspaceUrl($request),
                    $request,
                ),
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('diagnostic.runError', fallback: 'Could not run connection checks.'),
            ], 500);
        }
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

            return new JsonResponse([
                'success' => true,
                'tokens' => $this->formatTokensForView($tokens, $neverUsed),
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('tokens.loadError'),
            ], 500);
        }
    }

    /**
     * @param array{
     *   overallStatus: string,
     *   checks: list<array{
     *     id: string,
     *     status: string,
     *     labelKey: string,
     *     messageKey: string,
     *     howToCheckKey: string,
     *     fixHintKey: string,
     *     messageArguments: array<string, string|int>,
     *     fixHintArguments: array<string, string|int>
     *   }>
     * } $raw
     * @return array{
     *   overallStatus: string,
     *   checks: list<array{
     *     id: string,
     *     status: string,
     *     label: string,
     *     message: string,
     *     howToCheck: string,
     *     fixHint: string
     *   }>
     * }
     */
    private function translateDiagnostics(array $raw): array
    {
        $checks = [];
        foreach ($raw['checks'] as $check) {
            /** @var array<string, string|int> $messageArguments */
            $messageArguments = is_array($check['messageArguments'] ?? null) ? $check['messageArguments'] : [];
            /** @var array<string, string|int> $fixHintArguments */
            $fixHintArguments = is_array($check['fixHintArguments'] ?? null) ? $check['fixHintArguments'] : $messageArguments;
            $messageArgs = $this->stringifyTranslationArguments($messageArguments);
            $fixArgs = $this->stringifyTranslationArguments($fixHintArguments);

            $checks[] = [
                'id' => $check['id'],
                'status' => $check['status'],
                'label' => $this->translate($check['labelKey'], $messageArgs),
                'message' => $this->translate($check['messageKey'], $messageArgs),
                'howToCheck' => $this->translate($check['howToCheckKey'], $messageArgs),
                'fixHint' => $this->translate($check['fixHintKey'], $fixArgs),
            ];
        }

        return [
            'overallStatus' => (string)($raw['overallStatus'] ?? 'ok'),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, string|int> $arguments
     * @return array<string, string|int>
     */
    private function stringifyTranslationArguments(array $arguments): array
    {
        $normalized = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value) || is_int($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens
     * @return list<array{uid: int, client_name: string, created: string, expires: string, last_used: string, token_preview: string}>
     */
    private function formatTokensForView(array $tokens, string $neverUsed): array
    {
        return array_map(fn(array $token): array => [
            'uid' => $token['uid'],
            'client_name' => $token['client_name'],
            'created' => $this->formatTimestampForBackend($token['crdate']),
            'expires' => $this->formatTimestampForBackend($token['expires']),
            'last_used' => $token['last_used'] > 0 ? $this->formatTimestampForBackend($token['last_used']) : $neverUsed,
            'token_preview' => substr((string)$token['token'], 0, 20) . '...',
        ], $tokens);
    }

    private function formatTimestampForBackend(int $timestamp): string
    {
        $date = (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $locale = $this->getBackendLocale();

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
            $formatter->setTimeZone($date->getTimezone()->getName());
            $formatted = $formatter->format($date);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return str_starts_with($locale, 'de')
            ? $date->format('d.m.Y H:i')
            : $date->format('M j, Y g:i A');
    }

    private function getBackendLocale(): string
    {
        $backendUser = $this->getBackendUser();
        $languageKey = '';
        if ($backendUser instanceof BackendUserAuthentication && is_string($backendUser->uc['lang'] ?? null)) {
            $languageKey = strtolower($backendUser->uc['lang']);
        }

        return str_starts_with($languageKey, 'de') ? 'de' : 'en';
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
            $requestData = $this->ajaxRequestBodyParser->parseStringFields($request);

            if (!$this->validateCsrfToken($requestData)) {
                return new JsonResponse(['success' => false, 'message' => $this->translate('csrfFailed')], 403);
            }

            $clientName = trim($requestData['clientName'] ?? '');
            $clientType = $requestData['clientType'] ?? 'mcp-remote token';

            if ($clientName === '') {
                $allowedClientTypes = ['mcp-remote token'];
                if (!in_array($clientType, $allowedClientTypes, true)) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $this->translate('tokens.invalidClientType'),
                    ], 400);
                }
                $clientName = $clientType;
            }

            $existingTokens = $this->oauthService->getUserTokens($userId);
            foreach ($existingTokens as $token) {
                if ($token['client_name'] === $clientName) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $this->translate('tokens.alreadyExists', ['clientType' => $clientName]),
                    ], 400);
                }
            }

            $token = $this->oauthService->createDirectAccessToken($userId, $clientName, $request);

            return new JsonResponse([
                'success' => true,
                'message' => $this->translate('tokens.createdSuccessfully', ['clientType' => $clientName]),
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
     * @param array<string, string> $requestData
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
