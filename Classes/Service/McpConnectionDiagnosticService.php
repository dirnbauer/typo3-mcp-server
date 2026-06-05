<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Server-side MCP connection diagnostics for the backend user module.
 *
 * Runs checks from TYPO3 (no browser CORS) so editors get actionable guidance when MCP fails.
 */
final readonly class McpConnectionDiagnosticService
{
    private const SHARED_FIX_OK = 'diagnostic.fixOk';
    private const OAUTH_METADATA_PREFIX = 'diagnostic.oauthMetadata';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private SiteBaseUrlResolver $baseUrlResolver,
        private DiagnosticHttpClient $httpClient,
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
        $httpRequests = [
            'mcp_endpoint' => $this->httpSpec($baseUrl, '/mcp'),
            'oauth_authorization' => $this->httpSpec($baseUrl, '/.well-known/oauth-authorization-server'),
            'oauth_protected_resource' => $this->httpSpec($baseUrl, '/.well-known/oauth-protected-resource'),
        ];

        if ($this->isAuthHeaderDiagnosticEnabled()) {
            $httpRequests['auth_header'] = [
                'method' => 'GET',
                'url' => rtrim($baseUrl, '/') . '/mcp?test=auth',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer mcp-backend-diagnostic-check',
                ],
            ];
        }

        $httpResponses = $this->httpClient->requestMany($httpRequests);

        return [
            $this->checkBaseUrl($baseUrl),
            $this->evaluateMcpEndpoint($baseUrl, $httpResponses['mcp_endpoint'] ?? null),
            $this->evaluateWellKnownEndpoint(
                'oauth_authorization',
                'diagnostic.oauthAuthorization.label',
                'diagnostic.oauthAuthorization.fixHint',
                '/.well-known/oauth-authorization-server',
                $baseUrl,
                $httpResponses['oauth_authorization'] ?? null,
            ),
            $this->evaluateWellKnownEndpoint(
                'oauth_protected_resource',
                'diagnostic.oauthProtectedResource.label',
                'diagnostic.oauthProtectedResource.fixHint',
                '/.well-known/oauth-protected-resource',
                $baseUrl,
                $httpResponses['oauth_protected_resource'] ?? null,
            ),
            $this->evaluateAuthHeaderDiagnostic($baseUrl, $httpResponses['auth_header'] ?? null),
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
     * @return array{method: string, url: string, headers: array<string, string>}
     */
    private function httpSpec(string $baseUrl, string $path): array
    {
        return [
            'method' => 'GET',
            'url' => rtrim($baseUrl, '/') . $path,
            'headers' => ['Accept' => 'application/json'],
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
     * @param array{status: int, body: string}|null $response
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function evaluateMcpEndpoint(string $baseUrl, ?array $response): array
    {
        $prefix = 'diagnostic.mcpEndpoint';
        $url = rtrim($baseUrl, '/') . '/mcp';

        if ($response === null) {
            return $this->result(
                'mcp_endpoint',
                DiagnosticStatus::Error,
                $prefix,
                'diagnostic.http.unreachable',
                ['url' => $url],
                'diagnostic.http.fixUnreachable',
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
            'diagnostic.http.badStatus',
            $args,
            'diagnostic.http.fixBadStatus',
        );
    }

    /**
     * @param array{status: int, body: string}|null $response
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function evaluateWellKnownEndpoint(
        string $id,
        string $labelKey,
        string $fixHintKey,
        string $path,
        string $baseUrl,
        ?array $response,
    ): array {
        $url = rtrim($baseUrl, '/') . $path;
        $args = ['url' => $url];

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return $this->sharedResult(
                $id,
                DiagnosticStatus::Error,
                $labelKey,
                self::OAUTH_METADATA_PREFIX . '.fail',
                self::OAUTH_METADATA_PREFIX . '.howToCheck',
                $fixHintKey,
                ['url' => $url, 'status' => $response['status'] ?? 0],
            );
        }

        if (!str_contains($response['body'], '/mcp')) {
            return $this->sharedResult(
                $id,
                DiagnosticStatus::Warning,
                $labelKey,
                self::OAUTH_METADATA_PREFIX . '.noMcpReference',
                self::OAUTH_METADATA_PREFIX . '.howToCheck',
                $fixHintKey,
                $args,
            );
        }

        return $this->sharedResult(
            $id,
            DiagnosticStatus::Ok,
            $labelKey,
            self::OAUTH_METADATA_PREFIX . '.ok',
            self::OAUTH_METADATA_PREFIX . '.howToCheck',
            self::SHARED_FIX_OK,
            $args,
        );
    }

    /**
     * @param array{status: int, body: string}|null $response
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function evaluateAuthHeaderDiagnostic(string $baseUrl, ?array $response): array
    {
        $prefix = 'diagnostic.authHeader';

        if (!$this->isAuthHeaderDiagnosticEnabled()) {
            return $this->result('auth_header', DiagnosticStatus::Info, $prefix, 'disabled', [], 'fixDisabled');
        }

        $url = rtrim($baseUrl, '/') . '/mcp?test=auth';

        if ($response === null) {
            return $this->result(
                'auth_header',
                DiagnosticStatus::Error,
                $prefix,
                'diagnostic.http.unreachable',
                ['url' => $url],
                'diagnostic.http.fixUnreachable',
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
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function result(
        string $id,
        DiagnosticStatus $status,
        string $prefix,
        string $messageKeyOrOutcome,
        array $messageArguments,
        ?string $fixHintKey = null,
    ): array {
        $messageKey = str_contains($messageKeyOrOutcome, '.')
            ? $messageKeyOrOutcome
            : $prefix . '.' . $messageKeyOrOutcome;

        if ($fixHintKey === null) {
            $fixHintKey = $status === DiagnosticStatus::Ok ? self::SHARED_FIX_OK : $prefix . '.fixHint';
        }

        return [
            'id' => $id,
            'status' => $status->value,
            'labelKey' => $prefix . '.label',
            'messageKey' => $messageKey,
            'howToCheckKey' => $prefix . '.howToCheck',
            'fixHintKey' => $fixHintKey,
            'messageArguments' => $messageArguments,
            'fixHintArguments' => $messageArguments,
        ];
    }

    /**
     * @param array<string, string|int> $messageArguments
     * @return array{id: string, status: string, labelKey: string, messageKey: string, howToCheckKey: string, fixHintKey: string, messageArguments: array<string, string|int>, fixHintArguments: array<string, string|int>}
     */
    private function sharedResult(
        string $id,
        DiagnosticStatus $status,
        string $labelKey,
        string $messageKey,
        string $howToCheckKey,
        string $fixHintKey,
        array $messageArguments,
    ): array {
        return [
            'id' => $id,
            'status' => $status->value,
            'labelKey' => $labelKey,
            'messageKey' => $messageKey,
            'howToCheckKey' => $howToCheckKey,
            'fixHintKey' => $fixHintKey,
            'messageArguments' => $messageArguments,
            'fixHintArguments' => $messageArguments,
        ];
    }
}
