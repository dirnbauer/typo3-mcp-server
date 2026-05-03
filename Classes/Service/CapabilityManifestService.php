<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads and enforces Configuration/Capabilities.yaml.
 *
 * The manifest is the single source of truth for "what is this MCP server
 * allowed to do?". It declares (a) which subsystems the extension touches,
 * (b) which outbound network destinations are permitted, (c) which TYPO3
 * tables it owns, and (d) the required subsystems per MCP tool.
 *
 * The flow:
 *   1. ToolRegistry::getTool() asks `assertToolAllowed($name)` before handing
 *      a tool to a caller.
 *   2. Outbound HTTP code paths (UploadFileFromUrl, RenderRecord) ask
 *      `assertHostAllowed($host)` before opening a socket.
 *
 * Enforcement is gated by extension setting `enforceCapabilityManifest`
 * (default on). When disabled the service still reads the manifest so the
 * GetCapabilities tool keeps working, but no calls are blocked.
 */
final class CapabilityManifestService
{
    private const MANIFEST_PATH = 'Configuration/Capabilities.yaml';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $manifest = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->resolveManifestPath();
        if ($path === null || !is_file($path)) {
            $this->manifest = ['capabilities' => []];
            return $this->manifest;
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            $parsed = [];
        }

        $this->manifest = is_array($parsed) ? $parsed : [];
        return $this->manifest;
    }

    /**
     * @return list<string>
     */
    public function getDeclaredSubsystems(): array
    {
        $manifest = $this->getManifest();
        $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $subsystems = $capabilities['subsystems'] ?? [];
        if (!is_array($subsystems)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_string($v) ? $v : '',
            $subsystems,
        ), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @return list<string> required subsystems for a tool, or an empty list
     */
    public function getRequiredSubsystemsForTool(string $toolName): array
    {
        $manifest = $this->getManifest();
        $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $tools = $capabilities['tools'] ?? [];
        if (!is_array($tools) || !isset($tools[$toolName]) || !is_array($tools[$toolName])) {
            // Fail-closed default for unmapped tools: require both read and write.
            return ['database:read', 'database:write'];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_string($v) ? $v : '',
            $tools[$toolName],
        ), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @throws AccessDeniedException when enforcement is on and a required subsystem is missing
     */
    public function assertToolAllowed(string $toolName): void
    {
        if (!$this->isEnforced()) {
            return;
        }
        $required = $this->getRequiredSubsystemsForTool($toolName);
        $declared = $this->getDeclaredSubsystems();
        $missing = array_values(array_diff($required, $declared));
        if ($missing !== []) {
            throw new AccessDeniedException(
                sprintf(
                    'tool "%s" (manifest is missing subsystems: %s)',
                    $toolName,
                    implode(', ', $missing),
                ),
                'execute',
            );
        }
    }

    /**
     * @throws AccessDeniedException when enforcement is on and the host is not permitted
     */
    public function assertHostAllowed(string $host): void
    {
        if (!$this->isEnforced()) {
            return;
        }
        if ($host === '') {
            throw new AccessDeniedException('outbound request (empty host)', 'network');
        }

        $allowed = $this->getNetworkOutboundPolicy();
        $hostLower = strtolower($host);
        foreach ($allowed as $entry) {
            if ($entry === '*' || strtolower($entry) === $hostLower) {
                return;
            }
            if ($entry === 'self' && $this->matchesAnySiteHost($hostLower)) {
                return;
            }
            if (str_starts_with($entry, '*.')) {
                $suffix = strtolower(substr($entry, 1));
                if (str_ends_with($hostLower, $suffix)) {
                    return;
                }
            }
        }

        throw new AccessDeniedException(
            sprintf(
                'outbound request to "%s" (not in capability manifest network.outbound)',
                $host,
            ),
            'network',
        );
    }

    /**
     * @return list<string>
     */
    public function getNetworkOutboundPolicy(): array
    {
        $manifest = $this->getManifest();
        $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $network = is_array($capabilities['network'] ?? null) ? $capabilities['network'] : [];
        $outbound = $network['outbound'] ?? [];
        if (!is_array($outbound)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_string($v) ? $v : '',
            $outbound,
        ), static fn(string $v): bool => $v !== ''));
    }

    public function isEnforced(): bool
    {
        try {
            $config = $this->extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return true;
        }
        $value = is_array($config) ? ($config['enforceCapabilityManifest'] ?? null) : null;
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return true;
    }

    private function matchesAnySiteHost(string $hostLower): bool
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteHost = strtolower($site->getBase()->getHost());
                if ($siteHost !== '' && $siteHost === $hostLower) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // No sites configured (CLI / install) — `self` matches nothing.
        }
        return false;
    }

    private function resolveManifestPath(): ?string
    {
        // Prefer the extension folder path resolved by TYPO3 (handles both
        // composer and TER installations).
        $candidate = null;
        try {
            $candidate = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mcp_server') . self::MANIFEST_PATH;
        } catch (\Throwable) {
            // Extension manager not booted yet — fall through to the public path.
        }

        if (is_string($candidate) && is_file($candidate)) {
            return $candidate;
        }

        // Fallback for early-bootstrap calls (CLI before TYPO3 is booted).
        try {
            $public = Environment::getPublicPath() . '/typo3conf/ext/mcp_server/' . self::MANIFEST_PATH;
            if (is_file($public)) {
                return $public;
            }
        } catch (\Throwable) {
            // Environment not initialized (unit tests outside a TYPO3 instance).
        }

        // Last resort — resolve relative to this file's own location. Useful
        // in unit tests that don't bootstrap a full TYPO3.
        $relative = __DIR__ . '/../../' . self::MANIFEST_PATH;
        $resolved = realpath($relative);
        if (is_string($resolved) && is_file($resolved)) {
            return $resolved;
        }

        return null;
    }
}
