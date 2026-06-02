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
        private SiteBaseUrlResolver $baseUrlResolver,
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

        foreach ($this->collectChecks($baseUrl, $hasWorkspace, $registeredToolCount, $userTokenCount, $isLocalhost, $localStdioConfig) as $check) {
            $overall = $this->mergeWorst($overall, $this->appendCheck($checks, $check));
        }

        return [
            'overallStatus' => $overall->value,
            'checks' => $checks,
        ];
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $localStdioConfig
     * @return list<array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}>
     */
    private function collectChecks(
        string $baseUrl,
        bool $hasWorkspace,
        int $registeredToolCount,
        int $userTokenCount,
        bool $isLocalhost,
        array $localStdioConfig,
    ): array {
        return [
            $this->checkBaseUrl($baseUrl),
            $this->checkMcpEndpoint($baseUrl),
            $this->checkWellKnownEndpoint($baseUrl, 'oauth_authorization', '/.well-known/oauth-authorization-server', 'diagnostic.oauthAuthorization'),
            $this->checkWellKnownEndpoint($baseUrl, 'oauth_protected_resource', '/.well-known/oauth-protected-resource', 'diagnostic.oauthProtectedResource'),
            $this->checkAuthHeaderDiagnostic($baseUrl),
            $this->checkBoolean(
                'workspace',
                $hasWorkspace,
                DiagnosticStatus::Warning,
                'diagnostic.workspace',
                'ok',
                'missing',
            ),
            $this->checkBoolean(
                'tools',
                $registeredToolCount > 0,
                DiagnosticStatus::Error,
                'diagnostic.tools',
                'ok',
                'none',
                ['count' => $registeredToolCount],
            ),
            $this->checkLocalCli($localStdioConfig),
            $this->checkBoolean(
                'remote_reachability',
                !$isLocalhost,
                DiagnosticStatus::Warning,
                'diagnostic.remoteReachability',
                'public',
                'localhost',
            ),
            $this->checkBoolean(
                'user_tokens',
                $userTokenCount > 0,
                DiagnosticStatus::Info,
                'diagnostic.userTokens',
                'ok',
                'none',
                ['count' => $userTokenCount],
            ),
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
        $prefix = 'diagnostic.baseUrl';
        $args = ['url' => $baseUrl];

        if ($this->baseUrlResolver->hasConfiguredBaseUrl()) {
            return $this->result('base_url', DiagnosticStatus::Ok, $prefix, 'ok', $args);
        }

        return $this->result('base_url', DiagnosticStatus::Warning, $prefix, 'warning', $args);
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkMcpEndpoint(string $baseUrl): array
    {
        $prefix = 'diagnostic.mcpEndpoint';
        $url = rtrim($baseUrl, '/') . '/mcp';
        $response = $this->request('GET', $url, ['Accept' => 'application/json']);

        if ($response === null) {
            return $this->result(
                'mcp_endpoint',
                DiagnosticStatus::Error,
                $prefix,
                'unreachable',
                ['url' => $url],
                'fixUnreachable',
            );
        }

        $status = $response['status'];
        $args = ['url' => $url, 'status' => $status];

        if ($status === 401) {
            return $this->result('mcp_endpoint', DiagnosticStatus::Ok, $prefix, 'ok', $args);
        }

        if ($status >= 200 && $status < 300) {
            return $this->result('mcp_endpoint', DiagnosticStatus::Ok, $prefix, 'okReachable', $args);
        }

        return $this->result(
            'mcp_endpoint',
            DiagnosticStatus::Error,
            $prefix,
            'badStatus',
            $args,
            'fixBadStatus',
        );
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkWellKnownEndpoint(string $baseUrl, string $id, string $path, string $prefix): array
    {
        $url = rtrim($baseUrl, '/') . $path;
        $response = $this->request('GET', $url, ['Accept' => 'application/json']);

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->result(
                $id,
                DiagnosticStatus::Error,
                $prefix,
                'fail',
                ['url' => $url, 'status' => $response['status'] ?? 0],
            );
        }

        if (!str_contains($response['body'], '/mcp')) {
            return $this->result($id, DiagnosticStatus::Warning, $prefix, 'noMcpReference', ['url' => $url]);
        }

        return $this->result($id, DiagnosticStatus::Ok, $prefix, 'ok', ['url' => $url]);
    }

    /**
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkAuthHeaderDiagnostic(string $baseUrl): array
    {
        $prefix = 'diagnostic.authHeader';

        if (!$this->isAuthHeaderDiagnosticEnabled()) {
            return $this->result('auth_header', DiagnosticStatus::Info, $prefix, 'disabled', [], 'fixDisabled');
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
                $prefix,
                'unreachable',
                ['url' => $url],
                'fixUnreachable',
            );
        }

        if ($response['status'] === 403) {
            return $this->result('auth_header', DiagnosticStatus::Info, $prefix, 'disabled', [], 'fixDisabled');
        }

        $decoded = json_decode($response['body'], true);
        $received = is_array($decoded)
            && is_array($decoded['headers_received'] ?? null)
            && ($decoded['headers_received']['authorization'] ?? false) === true;

        if ($received) {
            return $this->result('auth_header', DiagnosticStatus::Ok, $prefix, 'ok', []);
        }

        return $this->result('auth_header', DiagnosticStatus::Error, $prefix, 'fail', []);
    }

    /**
     * @param array<string, string|int> $messageArguments
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkBoolean(
        string $id,
        bool $condition,
        DiagnosticStatus $failStatus,
        string $prefix,
        string $okOutcome,
        string $failOutcome,
        array $messageArguments = [],
    ): array {
        if ($condition) {
            return $this->result($id, DiagnosticStatus::Ok, $prefix, $okOutcome, $messageArguments);
        }

        return $this->result($id, $failStatus, $prefix, $failOutcome, $messageArguments);
    }

    /**
     * @param array{command: string, args: list<string>, cwd?: string} $localStdioConfig
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function checkLocalCli(array $localStdioConfig): array
    {
        $prefix = 'diagnostic.localCli';
        $command = $localStdioConfig['command'];
        $args = $localStdioConfig['args'];
        $binaryPath = $args[0] ?? '';

        if ($command === 'ddev') {
            $ddevProject = getenv('DDEV_PROJECT');

            return $this->result(
                'local_cli',
                DiagnosticStatus::Ok,
                $prefix,
                'ddev',
                ['project' => is_string($ddevProject) ? $ddevProject : ''],
                'fixDdev',
            );
        }

        if (!is_executable($this->resolvePhpBinary($command))) {
            return $this->result(
                'local_cli',
                DiagnosticStatus::Error,
                $prefix,
                'phpMissing',
                ['command' => $command],
                'fixPhp',
            );
        }

        if ($binaryPath === '' || !is_file($binaryPath)) {
            return $this->result(
                'local_cli',
                DiagnosticStatus::Error,
                $prefix,
                'binaryMissing',
                ['path' => $binaryPath],
                'fixBinary',
            );
        }

        return $this->result('local_cli', DiagnosticStatus::Ok, $prefix, 'ok', ['path' => $binaryPath]);
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
     * Builds a diagnostic result using the shared key naming convention.
     *
     * @param array<string, string|int> $messageArguments
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function result(
        string $id,
        DiagnosticStatus $status,
        string $prefix,
        string $outcome,
        array $messageArguments,
        ?string $fixOutcome = null,
    ): array {
        if ($fixOutcome === null) {
            $fixOutcome = $status === DiagnosticStatus::Ok ? 'fixOk' : 'fixHint';
        }

        return [
            'id' => $id,
            'status' => $status->value,
            'labelKey' => $prefix . '.label',
            'messageKey' => $prefix . '.' . $outcome,
            'howToCheckKey' => $prefix . '.howToCheck',
            'fixHintKey' => $prefix . '.' . $fixOutcome,
            'messageArguments' => $messageArguments,
            'fixHintArguments' => $messageArguments,
        ];
    }
}
