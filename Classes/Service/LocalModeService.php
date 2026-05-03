<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Detects whether the MCP server runs in a local-development environment
 * where the workspace-only / file-sandbox safety nets can be relaxed.
 *
 * Detection priority:
 *   1. Extension setting `localUnsafeMode` (off|auto|on) — auto is the default.
 *   2. DDEV environment indicators (`IS_DDEV_PROJECT`, `DDEV_PROJECT`, `DDEV_HOSTNAME`).
 *   3. TYPO3 application context (`Development/...`).
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
     * @return array{
     *     enabled: bool,
     *     setting: string,
     *     ddev: bool,
     *     development_context: bool,
     *     allows_live_writes: bool,
     *     allows_unrestricted_files: bool
     * }
     */
    public function describe(): array
    {
        return [
            'enabled' => $this->isLocalMode(),
            'setting' => $this->getConfigured(),
            'ddev' => $this->isDdev(),
            'development_context' => $this->isDevelopmentContext(),
            'allows_live_writes' => $this->allowsLiveWrites(),
            'allows_unrestricted_files' => $this->allowsUnrestrictedFileAccess(),
        ];
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
        try {
            $config = $this->extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return 'auto';
        }

        $value = is_array($config) ? ($config['localUnsafeMode'] ?? null) : null;
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['on', 'off', 'auto'], true)) {
                return $value;
            }
            // Legacy 1/0/true/false strings → on/off
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

        return 'auto';
    }
}
