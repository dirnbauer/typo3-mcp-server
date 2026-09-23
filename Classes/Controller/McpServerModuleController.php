<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Hn\McpServer\Http\AjaxRequestBodyParser;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\McpClientConfigBuilder;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\McpModulePartialRenderer;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Utility\BackendUserUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The "MCP Server" user module: connect a client, manage the access tokens
 * of the current backend user, run the connection check and list the tools
 * the server offers.
 *
 * The page is rendered once; the AJAX actions re-render the token list and
 * the connection check through the same Fluid partials, so the markup lives
 * in one place.
 */
final readonly class McpServerModuleController
{
    private const string LABELS = 'mcp_server.mod';
    private const string MODULE_LABELS = 'mcp_server.modules.mcp_server';
    private const string CSRF_FORM = 'mcpserver';
    private const string CSRF_ACTION = 'tokenManagement';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private ToolRegistry $toolRegistry,
        private OAuthService $oauthService,
        private UriBuilder $uriBuilder,
        private ConnectionPool $connectionPool,
        private FormProtectionFactory $formProtectionFactory,
        private McpConnectionDiagnosticService $connectionDiagnosticService,
        private SiteBaseUrlResolver $baseUrlResolver,
        private AjaxRequestBodyParser $ajaxRequestBodyParser,
        private McpClientConfigBuilder $clientConfigBuilder,
        private McpModulePartialRenderer $partialRenderer,
    ) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return new HtmlResponse($this->translate('accessDenied'), 403);
        }

        $userId = BackendUserUtility::getUserId($backendUser);
        $tokens = $this->formatTokensForView($this->oauthService->getUserTokens($userId));
        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);
        $endpointUrl = $baseUrl . '/mcp';
        $siteName = $this->clientConfigBuilder->getSiteName();
        $localStdioConfig = $this->clientConfigBuilder->buildLocalStdioConfig();
        $hasWorkspace = $this->hasAnyWorkspace();
        $diagnostics = $this->collectTranslatedDiagnostics($request, count($tokens), $hasWorkspace);
        $tools = $this->describeTools();

        $view = $this->moduleTemplateFactory->create($request);
        $title = $this->translate('title', domain: self::MODULE_LABELS);
        $view->setTitle($title);
        $this->registerDocHeaderButtons($view, $title);

        $view->assignMultiple([
            'endpointUrl' => $endpointUrl,
            'isLocalhost' => $this->isLocalhostUrl($baseUrl),
            'siteName' => $siteName,
            'cursorInstallUrl' => $this->clientConfigBuilder->buildCursorInstallUrl($siteName, $localStdioConfig),
            'cursorConfigJson' => $this->clientConfigBuilder->buildMcpServersConfigJson($siteName, $localStdioConfig),
            'codexConfigToml' => $this->clientConfigBuilder->buildCodexTomlConfig($siteName, $localStdioConfig),
            'tokens' => $tokens,
            'csrfToken' => $this->createCsrfToken(),
            'diagnostics' => $diagnostics,
            'hasWorkspace' => $hasWorkspace,
            'createWorkspaceUrl' => $this->buildCreateWorkspaceUrl($request),
            'tools' => $tools,
        ]);

        return $view->renderResponse('McpServerModule/Index');
    }

    /**
     * Current token list, rendered through the same partial as the page.
     */
    public function getUserTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->jsonError('accessDenied', 403);
        }

        try {
            $tokens = $this->formatTokensForView(
                $this->oauthService->getUserTokens(BackendUserUtility::getUserId($backendUser)),
            );

            return new JsonResponse([
                'success' => true,
                'count' => count($tokens),
                'tokens' => $tokens,
                'html' => $this->partialRenderer->renderTokens($tokens, $request),
            ]);
        } catch (\Throwable) {
            return $this->jsonError('tokens.loadError', 500);
        }
    }

    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->jsonError('accessDenied', 403);
        }

        $parsedBody = $this->ajaxRequestBodyParser->parseStringFields($request);
        if (!$this->validateCsrfToken($parsedBody)) {
            return $this->jsonError('csrfFailed', 403);
        }

        $tokenIdValue = $parsedBody['tokenId'] ?? '0';
        $tokenId = is_numeric($tokenIdValue) ? (int)$tokenIdValue : 0;
        if ($tokenId <= 0) {
            return $this->jsonError('tokens.invalidId', 400);
        }

        try {
            if (!$this->oauthService->revokeToken($tokenId, BackendUserUtility::getUserId($backendUser))) {
                return $this->jsonError('tokens.notFoundOrDenied', 404);
            }
        } catch (\Throwable) {
            return $this->jsonError('tokens.revokeError', 500);
        }

        return new JsonResponse(['success' => true, 'message' => $this->translate('tokens.revokedSuccess')]);
    }

    public function revokeAllTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->jsonError('accessDenied', 403);
        }

        if (!$this->validateCsrfToken($this->ajaxRequestBodyParser->parseStringFields($request))) {
            return $this->jsonError('csrfFailed', 403);
        }

        try {
            $revokedCount = $this->oauthService->revokeAllUserTokens(BackendUserUtility::getUserId($backendUser));
        } catch (\Throwable) {
            return $this->jsonError('tokens.revokeAllError', 500);
        }

        if ($revokedCount === 0) {
            return $this->jsonError('tokens.noTokensToRevoke', 404);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->translate('tokens.revokedCount', ['count' => $revokedCount]),
        ]);
    }

    /**
     * Mint a static access token for a client that cannot run the OAuth flow.
     * The plaintext is returned exactly once; only its hash is stored.
     */
    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->jsonError('accessDenied', 403);
        }

        $requestData = $this->ajaxRequestBodyParser->parseStringFields($request);
        if (!$this->validateCsrfToken($requestData)) {
            return $this->jsonError('csrfFailed', 403);
        }

        $clientName = trim($requestData['clientName'] ?? '');
        if ($clientName === '') {
            // Legacy callers sent a client type instead of a name.
            $clientType = $requestData['clientType'] ?? 'mcp-remote token';
            if ($clientType !== 'mcp-remote token') {
                return $this->jsonError('tokens.invalidClientType', 400);
            }
            $clientName = $clientType;
        }

        try {
            $userId = BackendUserUtility::getUserId($backendUser);
            foreach ($this->oauthService->getUserTokens($userId) as $token) {
                if ($token['client_name'] === $clientName) {
                    return $this->jsonError('tokens.alreadyExists', 400, ['clientType' => $clientName]);
                }
            }

            $token = $this->oauthService->createDirectAccessToken($userId, $clientName, $request);
        } catch (\Throwable) {
            return $this->jsonError('tokens.createError', 500);
        }

        return new JsonResponse([
            'success' => true,
            'message' => $this->translate('tokens.createdSuccessfully', ['clientType' => $clientName]),
            'token' => $token,
        ]);
    }

    /**
     * Re-run the server-side connection checks (no browser CORS involved).
     */
    public function runDiagnosticsAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return $this->jsonError('accessDenied', 403);
        }

        try {
            $tokens = $this->oauthService->getUserTokens(BackendUserUtility::getUserId($backendUser));
            $hasWorkspace = $this->hasAnyWorkspace();
            $diagnostics = $this->collectTranslatedDiagnostics($request, count($tokens), $hasWorkspace);

            return new JsonResponse([
                'success' => true,
                'overallStatus' => $diagnostics['overallStatus'],
                'diagnosticsHtml' => $this->partialRenderer->renderDiagnostics(
                    $diagnostics,
                    $hasWorkspace,
                    $this->buildCreateWorkspaceUrl($request),
                    $request,
                ),
            ]);
        } catch (\Throwable) {
            return $this->jsonError('diagnostic.runError', 500);
        }
    }

    private function registerDocHeaderButtons(ModuleTemplate $view, string $title): void
    {
        $label = $this->translate('tokens.create');
        $createTokenButton = $this->componentFactory->createGenericButton()
            ->setTag('button')
            ->setLabel($label)
            ->setTitle($label)
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setAttributes(['type' => 'button', 'data-mcp-action' => 'create-token']);

        $docHeader = $view->getDocHeaderComponent();
        $docHeader->getButtonBar()->addButton($createTokenButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
        $docHeader->setShortcutContext('user_mcp_server', $title);
    }

    /**
     * @return array{
     *   overallStatus: string,
     *   checks: list<array{id: string, status: string, label: string, message: string, howToCheck: string, fixHint: string}>
     * }
     */
    private function collectTranslatedDiagnostics(ServerRequestInterface $request, int $userTokenCount, bool $hasWorkspace): array
    {
        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);
        $raw = $this->connectionDiagnosticService->runChecks(
            $baseUrl,
            $hasWorkspace,
            count($this->toolRegistry->getTools()),
            $userTokenCount,
            $this->isLocalhostUrl($baseUrl),
            $this->clientConfigBuilder->buildLocalStdioConfig(),
        );

        $checks = [];
        foreach ($raw['checks'] as $check) {
            $checks[] = [
                'id' => $check['id'],
                'status' => $check['status'],
                'label' => $this->translate($check['labelKey'], $check['messageArguments']),
                'message' => $this->translate($check['messageKey'], $check['messageArguments']),
                'howToCheck' => $this->translate($check['howToCheckKey'], $check['messageArguments']),
                'fixHint' => $this->translate($check['fixHintKey'], $check['fixHintArguments']),
            ];
        }

        return ['overallStatus' => $raw['overallStatus'], 'checks' => $checks];
    }

    /**
     * The catalog as the connected client sees it, reduced to what an editor
     * needs to judge it: name, the first sentence of the description and
     * whether the tool reads or changes data.
     *
     * @return list<array{name: string, description: string, readOnly: bool, adminOnly: bool, devSiteOnly: bool, search: string}>
     */
    private function describeTools(): array
    {
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $name => $tool) {
            $schema = $tool->getSchema();
            $description = $this->firstSentence(is_string($schema['description'] ?? null) ? $schema['description'] : '');
            $tools[] = [
                'name' => $name,
                'description' => $description,
                'readOnly' => $this->isReadOnly($schema),
                'adminOnly' => $tool->isAdminOnly(),
                'devSiteOnly' => $tool->isDevSiteOnly(),
                'search' => mb_strtolower($name . ' ' . $description),
            ];
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function isReadOnly(array $schema): bool
    {
        $annotations = $schema['annotations'] ?? null;

        return is_array($annotations) && ($annotations['readOnlyHint'] ?? false) === true;
    }

    private function firstSentence(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $text, $matches) === 1) {
            return $matches[1];
        }

        return $text;
    }

    /**
     * @param list<array{uid: int, client_name: string, token: string, crdate: int, expires: int, last_used: int}> $tokens
     * @return list<array{uid: int, clientName: string, created: string, createdIso: string, expires: string, expiresIso: string, lastUsed: string, lastUsedIso: string}>
     */
    private function formatTokensForView(array $tokens): array
    {
        $neverUsed = $this->translate('tokens.neverUsed');

        return array_map(fn(array $token): array => [
            'uid' => $token['uid'],
            'clientName' => $token['client_name'],
            'created' => $this->formatTimestamp($token['crdate']),
            'createdIso' => $this->isoDate($token['crdate']),
            'expires' => $this->formatTimestamp($token['expires']),
            'expiresIso' => $this->isoDate($token['expires']),
            'lastUsed' => $token['last_used'] > 0 ? $this->formatTimestamp($token['last_used']) : $neverUsed,
            'lastUsedIso' => $token['last_used'] > 0 ? $this->isoDate($token['last_used']) : '',
        ], $tokens);
    }

    private function formatTimestamp(int $timestamp): string
    {
        $date = new \DateTimeImmutable('@' . $timestamp)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $locale = (string)($this->getLanguageService()->getLocale() ?? 'en');

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $date->getTimezone());
            $formatted = $formatter->format($date);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return $date->format('Y-m-d H:i');
    }

    private function isoDate(int $timestamp): string
    {
        return new \DateTimeImmutable('@' . $timestamp)->format(\DateTimeInterface::ATOM);
    }

    private function buildCreateWorkspaceUrl(ServerRequestInterface $request): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_workspace' => [0 => 'new']],
            'returnUrl' => (string)$request->getUri(),
        ]);
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
     * True when the host resolves only to loopback or private addresses, so
     * cloud-hosted clients cannot reach it. Both A and AAAA records count, to
     * avoid false positives on IPv6-only hosts.
     */
    private function isLocalhostUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return true;
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            return false;
        }

        return !array_any(
            $ips,
            static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
        );
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach ($records ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private function createCsrfToken(): string
    {
        return $this->formProtectionFactory->createForType('backend')->generateToken(self::CSRF_FORM, self::CSRF_ACTION);
    }

    /**
     * @param array<string, string> $requestData
     */
    private function validateCsrfToken(array $requestData): bool
    {
        $token = $requestData['csrfToken'] ?? '';

        return $token !== ''
            && $this->formProtectionFactory->createForType('backend')->validateToken($token, self::CSRF_FORM, self::CSRF_ACTION);
    }

    /**
     * @param array<string, string|int> $arguments
     */
    private function jsonError(string $labelId, int $status, array $arguments = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $this->translate($labelId, $arguments)], $status);
    }

    /**
     * @param array<string, string|int> $arguments
     */
    private function translate(string $id, array $arguments = [], string $domain = self::LABELS): string
    {
        return (string)($this->getLanguageService()->translate($id, $domain, $arguments) ?? $id);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function getLanguageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('The backend language service is not initialized.', 1790000001);
        }

        return $languageService;
    }
}
