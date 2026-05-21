<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Server-side MCP connection diagnostics for the backend user module.
 *
 * Runs checks from TYPO3 (no browser CORS) so editors get actionable guidance when MCP fails.
 */
final readonly class McpConnectionDiagnosticService
{
    private const REQUEST_TIMEOUT = 8;

    public function __construct(
        private RequestFactory $requestFactory,
        private ExtensionConfiguration $extensionConfiguration,
        private LocalModeService $localModeService,
    ) {}

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $localStdioConfig
     * @return array{
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
     * }
     */
    public function runChecks(
        string $baseUrl,
        bool $hasWorkspace,
        int $registeredToolCount,
        int $userTokenCount,
        bool $isLocalhost,
        array $localStdioConfig,
    ): array {
        /** @var list<array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}> $checks */
        $checks = [];
        $overall = DiagnosticStatus::Ok;

        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkBaseUrl($baseUrl)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkMcpEndpoint($baseUrl)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkOAuthAuthorizationMetadata($baseUrl)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkOAuthProtectedResourceMetadata($baseUrl)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkAuthHeaderDiagnostic($baseUrl)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkWorkspace($hasWorkspace)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkRegisteredTools($registeredToolCount)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkLocalCli($localStdioConfig)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkRemoteReachability($isLocalhost)));
        $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $this->checkUserTokens($userTokenCount)));

        return [
            'overallStatus' => $overall->value,
            'checks' => $checks,
        ];
    }

    /**
     * @param list<array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}> $checks
     * @param array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>} $check
     */
    private function appendCheck(array &$checks, array $check): DiagnosticStatus
    {
        $checks[] = $check;

        return DiagnosticStatus::from($check['status']);
    }

    private function mergeWorst(DiagnosticStatus $current, DiagnosticStatus $next): DiagnosticStatus
    {
        return $next->isWorseThan($current) ? $next : $current;
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkBaseUrl(string $baseUrl): array
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $configured = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['reverseProxyBaseUrl'] ?? null)
            : null;

        if (is_string($configured) && $configured !== '') {
            return $this->result(
                'base_url',
                DiagnosticStatus::Ok,
                'diagnostic.baseUrl.label',
                'diagnostic.baseUrl.ok',
                'diagnostic.baseUrl.howToCheck',
                'diagnostic.baseUrl.fixOk',
                ['url' => $baseUrl],
            );
        }

        return $this->result(
            'base_url',
            DiagnosticStatus::Warning,
            'diagnostic.baseUrl.label',
            'diagnostic.baseUrl.warning',
            'diagnostic.baseUrl.howToCheck',
            'diagnostic.baseUrl.fixHint',
            ['url' => $baseUrl],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkMcpEndpoint(string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/') . '/mcp';
        $response = $this->request('GET', $url, ['Accept' => 'application/json']);

        if ($response === null) {
            return $this->result(
                'mcp_endpoint',
                DiagnosticStatus::Error,
                'diagnostic.mcpEndpoint.label',
                'diagnostic.mcpEndpoint.unreachable',
                'diagnostic.mcpEndpoint.howToCheck',
                'diagnostic.mcpEndpoint.fixUnreachable',
                ['url' => $url],
            );
        }

        $status = $response['status'];
        if ($status === 401) {
            return $this->result(
                'mcp_endpoint',
                DiagnosticStatus::Ok,
                'diagnostic.mcpEndpoint.label',
                'diagnostic.mcpEndpoint.ok',
                'diagnostic.mcpEndpoint.howToCheck',
                'diagnostic.mcpEndpoint.fixOk',
                ['url' => $url, 'status' => $status],
            );
        }

        if ($status >= 200 && $status < 300) {
            return $this->result(
                'mcp_endpoint',
                DiagnosticStatus::Ok,
                'diagnostic.mcpEndpoint.label',
                'diagnostic.mcpEndpoint.okReachable',
                'diagnostic.mcpEndpoint.howToCheck',
                'diagnostic.mcpEndpoint.fixOk',
                ['url' => $url, 'status' => $status],
            );
        }

        return $this->result(
            'mcp_endpoint',
            DiagnosticStatus::Error,
            'diagnostic.mcpEndpoint.label',
            'diagnostic.mcpEndpoint.badStatus',
            'diagnostic.mcpEndpoint.howToCheck',
            'diagnostic.mcpEndpoint.fixBadStatus',
            ['url' => $url, 'status' => $status],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkOAuthAuthorizationMetadata(string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/') . '/.well-known/oauth-authorization-server';
        $response = $this->request('GET', $url, ['Accept' => 'application/json']);

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->result(
                'oauth_authorization',
                DiagnosticStatus::Error,
                'diagnostic.oauthAuthorization.label',
                'diagnostic.oauthAuthorization.fail',
                'diagnostic.oauthAuthorization.howToCheck',
                'diagnostic.oauthAuthorization.fixHint',
                ['url' => $url, 'status' => $response['status'] ?? 0],
            );
        }

        $body = $response['body'];
        if (!str_contains($body, '/mcp')) {
            return $this->result(
                'oauth_authorization',
                DiagnosticStatus::Warning,
                'diagnostic.oauthAuthorization.label',
                'diagnostic.oauthAuthorization.noMcpReference',
                'diagnostic.oauthAuthorization.howToCheck',
                'diagnostic.oauthAuthorization.fixHint',
                ['url' => $url],
            );
        }

        return $this->result(
            'oauth_authorization',
            DiagnosticStatus::Ok,
            'diagnostic.oauthAuthorization.label',
            'diagnostic.oauthAuthorization.ok',
            'diagnostic.oauthAuthorization.howToCheck',
            'diagnostic.oauthAuthorization.fixOk',
            ['url' => $url],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkOAuthProtectedResourceMetadata(string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/') . '/.well-known/oauth-protected-resource';
        $response = $this->request('GET', $url, ['Accept' => 'application/json']);

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->result(
                'oauth_protected_resource',
                DiagnosticStatus::Error,
                'diagnostic.oauthProtectedResource.label',
                'diagnostic.oauthProtectedResource.fail',
                'diagnostic.oauthProtectedResource.howToCheck',
                'diagnostic.oauthProtectedResource.fixHint',
                ['url' => $url, 'status' => $response['status'] ?? 0],
            );
        }

        $body = $response['body'];
        if (!str_contains($body, '/mcp')) {
            return $this->result(
                'oauth_protected_resource',
                DiagnosticStatus::Warning,
                'diagnostic.oauthProtectedResource.label',
                'diagnostic.oauthProtectedResource.noMcpReference',
                'diagnostic.oauthProtectedResource.howToCheck',
                'diagnostic.oauthProtectedResource.fixHint',
                ['url' => $url],
            );
        }

        return $this->result(
            'oauth_protected_resource',
            DiagnosticStatus::Ok,
            'diagnostic.oauthProtectedResource.label',
            'diagnostic.oauthProtectedResource.ok',
            'diagnostic.oauthProtectedResource.howToCheck',
            'diagnostic.oauthProtectedResource.fixOk',
            ['url' => $url],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkAuthHeaderDiagnostic(string $baseUrl): array
    {
        if (!$this->isAuthHeaderDiagnosticEnabled()) {
            return $this->result(
                'auth_header',
                DiagnosticStatus::Info,
                'diagnostic.authHeader.label',
                'diagnostic.authHeader.disabled',
                'diagnostic.authHeader.howToCheck',
                'diagnostic.authHeader.fixDisabled',
                [],
            );
        }

        $url = rtrim($baseUrl, '/') . '/mcp?test=auth';
        $response = $this->request('GET', $url, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer mcp-backend-diagnostic-check',
        ]);

        if ($response === null) {
            return $this->result(
                'auth_header',
                DiagnosticStatus::Error,
                'diagnostic.authHeader.label',
                'diagnostic.authHeader.unreachable',
                'diagnostic.authHeader.howToCheck',
                'diagnostic.authHeader.fixUnreachable',
                ['url' => $url],
            );
        }

        if ($response['status'] === 403) {
            return $this->result(
                'auth_header',
                DiagnosticStatus::Info,
                'diagnostic.authHeader.label',
                'diagnostic.authHeader.disabled',
                'diagnostic.authHeader.howToCheck',
                'diagnostic.authHeader.fixDisabled',
                [],
            );
        }

        $decoded = json_decode($response['body'], true);
        $received = is_array($decoded)
            && is_array($decoded['headers_received'] ?? null)
            && ($decoded['headers_received']['authorization'] ?? false) === true;

        if ($received) {
            return $this->result(
                'auth_header',
                DiagnosticStatus::Ok,
                'diagnostic.authHeader.label',
                'diagnostic.authHeader.ok',
                'diagnostic.authHeader.howToCheck',
                'diagnostic.authHeader.fixOk',
                [],
            );
        }

        return $this->result(
            'auth_header',
            DiagnosticStatus::Error,
            'diagnostic.authHeader.label',
            'diagnostic.authHeader.fail',
            'diagnostic.authHeader.howToCheck',
            'diagnostic.authHeader.fixHint',
            [],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkWorkspace(bool $hasWorkspace): array
    {
        if ($hasWorkspace) {
            return $this->result(
                'workspace',
                DiagnosticStatus::Ok,
                'diagnostic.workspace.label',
                'diagnostic.workspace.ok',
                'diagnostic.workspace.howToCheck',
                'diagnostic.workspace.fixOk',
                [],
            );
        }

        return $this->result(
            'workspace',
            DiagnosticStatus::Warning,
            'diagnostic.workspace.label',
            'diagnostic.workspace.missing',
            'diagnostic.workspace.howToCheck',
            'diagnostic.workspace.fixHint',
            [],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkRegisteredTools(int $count): array
    {
        if ($count > 0) {
            return $this->result(
                'tools',
                DiagnosticStatus::Ok,
                'diagnostic.tools.label',
                'diagnostic.tools.ok',
                'diagnostic.tools.howToCheck',
                'diagnostic.tools.fixOk',
                ['count' => $count],
            );
        }

        return $this->result(
            'tools',
            DiagnosticStatus::Error,
            'diagnostic.tools.label',
            'diagnostic.tools.none',
            'diagnostic.tools.howToCheck',
            'diagnostic.tools.fixHint',
            [],
        );
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $localStdioConfig
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkLocalCli(array $localStdioConfig): array
    {
        $command = $localStdioConfig['command'];
        $args = $localStdioConfig['args'];
        $binaryPath = $args[0] ?? '';

        if ($command === 'ddev') {
            $ddevProject = getenv('DDEV_PROJECT');

            return $this->result(
                'local_cli',
                DiagnosticStatus::Ok,
                'diagnostic.localCli.label',
                'diagnostic.localCli.ddev',
                'diagnostic.localCli.howToCheck',
                'diagnostic.localCli.fixDdev',
                ['project' => is_string($ddevProject) ? $ddevProject : ''],
            );
        }

        if (!is_executable($this->resolvePhpBinary($command))) {
            return $this->result(
                'local_cli',
                DiagnosticStatus::Error,
                'diagnostic.localCli.label',
                'diagnostic.localCli.phpMissing',
                'diagnostic.localCli.howToCheck',
                'diagnostic.localCli.fixPhp',
                ['command' => $command],
            );
        }

        if ($binaryPath === '' || !is_file($binaryPath)) {
            return $this->result(
                'local_cli',
                DiagnosticStatus::Error,
                'diagnostic.localCli.label',
                'diagnostic.localCli.binaryMissing',
                'diagnostic.localCli.howToCheck',
                'diagnostic.localCli.fixBinary',
                ['path' => $binaryPath],
            );
        }

        return $this->result(
            'local_cli',
            DiagnosticStatus::Ok,
            'diagnostic.localCli.label',
            'diagnostic.localCli.ok',
            'diagnostic.localCli.howToCheck',
            'diagnostic.localCli.fixOk',
            ['path' => $binaryPath],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkRemoteReachability(bool $isLocalhost): array
    {
        if (!$isLocalhost) {
            return $this->result(
                'remote_reachability',
                DiagnosticStatus::Ok,
                'diagnostic.remoteReachability.label',
                'diagnostic.remoteReachability.public',
                'diagnostic.remoteReachability.howToCheck',
                'diagnostic.remoteReachability.fixOk',
                [],
            );
        }

        return $this->result(
            'remote_reachability',
            DiagnosticStatus::Warning,
            'diagnostic.remoteReachability.label',
            'diagnostic.remoteReachability.localhost',
            'diagnostic.remoteReachability.howToCheck',
            'diagnostic.remoteReachability.fixHint',
            [],
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkUserTokens(int $count): array
    {
        if ($count > 0) {
            return $this->result(
                'user_tokens',
                DiagnosticStatus::Ok,
                'diagnostic.userTokens.label',
                'diagnostic.userTokens.ok',
                'diagnostic.userTokens.howToCheck',
                'diagnostic.userTokens.fixOk',
                ['count' => $count],
            );
        }

        return $this->result(
            'user_tokens',
            DiagnosticStatus::Info,
            'diagnostic.userTokens.label',
            'diagnostic.userTokens.none',
            'diagnostic.userTokens.howToCheck',
            'diagnostic.userTokens.fixHint',
            [],
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}|null
     */
    private function request(string $method, string $url, array $headers = []): ?array
    {
        try {
            $options = [
                'timeout' => self::REQUEST_TIMEOUT,
                'allow_redirects' => true,
                'headers' => $headers,
            ];

            if ($this->localModeService->isLocalMode() && str_starts_with($url, 'https://')) {
                $options['verify'] = false;
            }

            $response = $this->requestFactory->request($url, $method, $options);
            $body = (string)$response->getBody();

            return [
                'status' => $response->getStatusCode(),
                'body' => $body,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAuthHeaderDiagnosticEnabled(): bool
    {
        try {
            $configuration = $this->extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($configuration) || !array_key_exists('enableMcpAuthHeaderDiagnostic', $configuration)) {
            return false;
        }

        $value = $configuration['enableMcpAuthHeaderDiagnostic'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
        }

        return (bool)$value;
    }

    private function resolvePhpBinary(string $command): string
    {
        if ($command === 'php' || $command === 'php.exe') {
            return PHP_BINARY;
        }

        return $command;
    }

    /**
     * @param array<string, string|int> $messageArguments
     * @param array<string, string|int> $fixHintArguments
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function result(
        string $id,
        DiagnosticStatus $status,
        string $labelKey,
        string $messageKey,
        string $howToCheckKey,
        string $fixHintKey,
        array $messageArguments,
        array $fixHintArguments = [],
    ): array {
        return [
            'id' => $id,
            'status' => $status->value,
            'labelKey' => $labelKey,
            'messageKey' => $messageKey,
            'howToCheckKey' => $howToCheckKey,
            'fixHintKey' => $fixHintKey,
            'messageArguments' => $messageArguments,
            'fixHintArguments' => $fixHintArguments !== [] ? $fixHintArguments : $messageArguments,
        ];
    }
}
