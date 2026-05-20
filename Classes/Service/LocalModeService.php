<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Detects whether the MCP server runs in a local-development environment
 * where the workspace-only / file-sandbox safety nets can be relaxed.
 *
 * Detection priority:
 *   1. Strict sandbox overrides via TYPO3 feature flag / User TSconfig.
 *   2. User TSconfig `options.mcpServer.localUnsafeMode` (off|auto|on).
 *   3. Extension setting `localUnsafeMode` (off|auto|on) — auto is the default.
 *   4. DDEV environment indicators (`IS_DDEV_PROJECT`, `DDEV_PROJECT`, `DDEV_HOSTNAME`).
 *   5. TYPO3 application context (`Development/...`).
 *
 * "Local mode" never bypasses authentication, OAuth, or admin/permission checks.
 * It only loosens the workspace-only-writes and `1:/mcp/`-only file rules — the
 * features that are explicitly safety nets for production, not access control.
 *
 * Read the SecurityAudit.rst document before touching this class.
 */
final readonly class LocalModeService
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Master toggle: should the server run with relaxed safety nets?
     */
    public function isLocalMode(): bool
    {
        if ($this->enforcesStrictSandbox()) {
            return false;
        }

        $configured = $this->getConfigured();
        if ($configured === 'on') {
            return true;
        }
        if ($configured === 'off') {
            return false;
        }
        // 'auto' — derive from environment
        return $this->isDdev() || $this->isDevelopmentContext();
    }

    /**
     * Are writes to the live workspace (workspace_id=0) accepted?
     * Falls back to the master toggle but kept as its own method so a future
     * fine-grained override (allow live writes but not bypass sandbox, etc.)
     * is a one-line change.
     */
    public function allowsLiveWrites(): bool
    {
        return $this->isLocalMode();
    }

    /**
     * Should the file sandbox accept any storage/path inside the configured
     * TYPO3 file storages, instead of the `1:/mcp/` jail?
     */
    public function allowsUnrestrictedFileAccess(): bool
    {
        return $this->isLocalMode();
    }

    /**
     * Should outbound HTTP be unrestricted (manifest network.outbound and
     * the SSRF private-IP filter both bypassed)? Required so DDEV-based
     * workflows like "fetch this Unsplash image" work without operators
     * having to edit Configuration/Capabilities.yaml every session.
     */
    public function allowsUnrestrictedOutbound(): bool
    {
        return $this->isLocalMode();
    }

    /**
     * @return array{
     *     enabled: bool,
     *     setting: string,
     *     extension_setting: string,
     *     tsconfig_setting: string|null,
     *     strict_sandbox: bool,
     *     ddev: bool,
     *     development_context: bool,
     *     allows_live_writes: bool,
     *     allows_unrestricted_files: bool,
     *     allows_unrestricted_outbound: bool,
     *     allows_dev_tools: bool
     * }
     */
    public function describe(): array
    {
        return [
            'enabled' => $this->isLocalMode(),
            'setting' => $this->getConfigured(),
            'extension_setting' => $this->getExtensionConfigured(),
            'tsconfig_setting' => $this->getTsConfigMode(),
            'strict_sandbox' => $this->enforcesStrictSandbox(),
            'ddev' => $this->isDdev(),
            'development_context' => $this->isDevelopmentContext(),
            'allows_live_writes' => $this->allowsLiveWrites(),
            'allows_unrestricted_files' => $this->allowsUnrestrictedFileAccess(),
            'allows_unrestricted_outbound' => $this->allowsUnrestrictedOutbound(),
            'allows_dev_tools' => $this->isLocalMode(),
        ];
    }

    /**
     * Whether dev-site MCP tools (site settings authoring, ViewHelper reference,
     * TCA resources, XLF scaffolding) should be exposed.
     *
     * Intentionally tied to {@see isLocalMode()} — the same gate that unlocks
     * live writes and the file sandbox. Use strictSandbox to force production
     * behaviour on DDEV for all three.
     */
    public function allowsDevTools(): bool
    {
        return $this->isLocalMode();
    }

    private function isDdev(): bool
    {
        foreach (['IS_DDEV_PROJECT', 'DDEV_PROJECT', 'DDEV_HOSTNAME', 'DDEV_TLD'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '' && strtolower($value) !== 'false' && $value !== '0') {
                return true;
            }
        }
        return false;
    }

    private function isDevelopmentContext(): bool
    {
        try {
            return Environment::getContext()->isDevelopment();
        } catch (\Throwable) {
            return false;
        }
    }

    private function getConfigured(): string
    {
        return $this->getTsConfigMode() ?? $this->getExtensionConfigured();
    }

    private function getExtensionConfigured(): string
    {
        try {
            $config = $this->extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return 'auto';
        }

        return $this->normalizeMode(is_array($config) ? ($config['localUnsafeMode'] ?? null) : null);
    }

    private function getTsConfigMode(): ?string
    {
        $value = $this->getMcpUserTsConfigValue('localUnsafeMode');
        if ($value === null) {
            return null;
        }

        return $this->normalizeModeOrNull($value);
    }

    private function enforcesStrictSandbox(): bool
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $sysConfig = is_array($confVars) && is_array($confVars['SYS'] ?? null) ? $confVars['SYS'] : [];
        $features = is_array($sysConfig['features'] ?? null) ? $sysConfig['features'] : [];
        if ($this->isTruthy($features['mcpServer.strictSandbox'] ?? null)) {
            return true;
        }

        return $this->isTruthy($this->getMcpUserTsConfigValue('strictSandbox'));
    }

    private function getMcpUserTsConfigValue(string $key): mixed
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        try {
            $tsConfig = $backendUser->getTSConfig();
        } catch (\Throwable) {
            return null;
        }

        $options = $tsConfig['options.'] ?? [];
        if (!is_array($options)) {
            return null;
        }

        $mcpServer = $options['mcpServer.'] ?? $options['mcp_server.'] ?? [];
        if (!is_array($mcpServer)) {
            return null;
        }

        return $mcpServer[$key] ?? null;
    }

    private function normalizeMode(mixed $value): string
    {
        return $this->normalizeModeOrNull($value) ?? 'auto';
    }

    private function normalizeModeOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['on', 'off', 'auto'], true)) {
                return $value;
            }
            if (in_array($value, ['1', 'true', 'yes'], true)) {
                return 'on';
            }
            if (in_array($value, ['0', 'false', 'no'], true)) {
                return 'off';
            }
        }
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        if (is_int($value)) {
            return $value === 1 ? 'on' : 'off';
        }

        return null;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
