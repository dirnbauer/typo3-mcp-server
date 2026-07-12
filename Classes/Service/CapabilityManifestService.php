<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Reads and enforces Configuration/Capabilities.yaml.
 *
 * The manifest is the single source of truth for "what is this MCP server
 * allowed to do?". Its public fields follow the TYPO3 capability-manifest
 * 1.0 schema. Fine-grained MCP runtime policy is namespaced under `x-mcp`,
 * which keeps custom gates out of the public subsystem enum while retaining
 * runtime enforcement.
 *
 * The flow:
 *   1. ToolRegistry::getTool() asks `assertToolAllowed($name)` before handing
 *      a tool to a caller.
 *   2. Outbound HTTP code paths (UploadFileFromUrl, RenderRecord) ask
 *      `assertUrlAllowed($url)` before opening a socket.
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
        private readonly ?LocalModeService $localMode = null,
        /**
         * Optional manifest-path override for tests. Production code never
         * passes this — DI auto-wires nothing into it and the class falls
         * back to extension/public/relative resolution.
         */
        private readonly ?string $manifestPathOverride = null,
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
        $capabilities = $this->getCapabilities();
        $mcp = $this->getMcpExtension();

        $public = $this->normalizeStringList($capabilities['subsystems'] ?? []);
        $runtime = $this->normalizeStringList($mcp['runtime_subsystems'] ?? []);

        return array_values(array_unique([...$public, ...$runtime]));
    }

    /**
     * Subsystems whose prerequisites are all satisfied. A subsystem is
     * effective only when itself AND its `requires:` chain are all in
     * the declared list. Used by `assertToolAllowed` so removing
     * `database:write` automatically disables `file:write`-dependent
     * tools too.
     *
     * @return list<string>
     */
    public function getEffectiveSubsystems(): array
    {
        $declared = array_fill_keys($this->getDeclaredSubsystems(), true);
        $rules = $this->getRequiresMap();

        $effective = [];
        foreach (array_keys($declared) as $subsystem) {
            if ($this->isSubsystemSatisfied($subsystem, $declared, $rules, [])) {
                $effective[] = $subsystem;
            }
        }
        return $effective;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getRequiresMap(): array
    {
        $capabilities = $this->getCapabilities();
        $mcp = $this->getMcpExtension();
        // Legacy top-level fallback keeps operator manifests made for older
        // extension releases working. A present x-mcp map is authoritative,
        // including when deliberately empty.
        $requires = array_key_exists('requires', $mcp)
            ? $mcp['requires']
            : ($capabilities['requires'] ?? []);
        if (!is_array($requires)) {
            return [];
        }

        $normalized = [];
        foreach ($requires as $name => $deps) {
            if (!is_string($name) || $name === '' || !is_array($deps)) {
                continue;
            }
            $list = array_values(array_filter(array_map(
                static fn(mixed $v): string => is_string($v) ? $v : '',
                $deps,
            ), static fn(string $v): bool => $v !== ''));
            $normalized[$name] = $list;
        }
        return $normalized;
    }

    /**
     * @param array<string, true> $declared
     * @param array<string, list<string>> $rules
     * @param array<string, true> $visited
     */
    private function isSubsystemSatisfied(string $subsystem, array $declared, array $rules, array $visited): bool
    {
        if (!isset($declared[$subsystem])) {
            return false;
        }
        if (isset($visited[$subsystem])) {
            // Circular requires — treat as satisfied to avoid infinite recursion;
            // operators get the regular "missing" error from the originating call.
            return true;
        }
        $visited[$subsystem] = true;

        foreach ($rules[$subsystem] ?? [] as $dep) {
            if (!$this->isSubsystemSatisfied($dep, $declared, $rules, $visited)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string> required subsystems for a declared tool, or an
     *                      empty list when it is undeclared/malformed
     */
    public function getRequiredSubsystemsForTool(string $toolName): array
    {
        $tools = $this->getToolPolicyDefinitions();
        if (!array_key_exists($toolName, $tools) || !is_array($tools[$toolName])) {
            return [];
        }
        return $this->normalizeStringList($tools[$toolName]);
    }

    /**
     * Exact Symfony command inventory declared by this extension.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCommandDefinitions(): array
    {
        return $this->getMcpDefinitions('commands');
    }

    /**
     * Bundled Agent Skills inventory. Skills are an MCP extension concern,
     * not a value in the protocol's server-capabilities object.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSkillDefinitions(): array
    {
        return $this->getMcpDefinitions('skills');
    }

    /**
     * @return array<string, mixed>
     */
    public function getProtocolMetadata(): array
    {
        $protocol = $this->getMcpExtension()['protocol'] ?? [];
        return is_array($protocol) ? $this->normalizeStringKeyedMap($protocol) : [];
    }

    /**
     * @throws AccessDeniedException when enforcement is on and a required subsystem is missing
     *                               or any of its prerequisites are missing
     */
    public function assertToolAllowed(string $toolName): void
    {
        if (!$this->isEnforced()) {
            return;
        }
        $tools = $this->getToolPolicyDefinitions();
        if (!array_key_exists($toolName, $tools) || !is_array($tools[$toolName])) {
            throw new AccessDeniedException(
                sprintf('tool "%s" (not declared in capability manifest)', $toolName),
                'execute',
            );
        }
        $required = $this->getRequiredSubsystemsForTool($toolName);
        $effective = $this->getEffectiveSubsystems();
        $missing = array_values(array_diff($required, $effective));
        if ($missing !== []) {
            // Distinguish "subsystem not declared" from "subsystem declared but
            // its prerequisites are missing" so the operator knows where to
            // look in Capabilities.yaml.
            $declared = $this->getDeclaredSubsystems();
            $rules = $this->getRequiresMap();
            $details = [];
            foreach ($missing as $subsystem) {
                if (!in_array($subsystem, $declared, true)) {
                    $details[] = $subsystem;
                    continue;
                }
                $unmet = array_values(array_diff($rules[$subsystem] ?? [], $declared));
                $details[] = $unmet === []
                    ? $subsystem
                    : sprintf('%s (needs: %s)', $subsystem, implode(', ', $unmet));
            }
            throw new AccessDeniedException(
                sprintf(
                    'tool "%s" (manifest is missing subsystems: %s)',
                    $toolName,
                    implode(', ', $details),
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
        $this->assertOutboundTargetAllowed($host, null);
    }

    /**
     * Enforce both the host declaration and its optional protocol field.
     *
     * @throws AccessDeniedException when enforcement is on and the URL is not permitted
     */
    public function assertUrlAllowed(string $url): void
    {
        $parsed = parse_url($url);
        $host = is_array($parsed) && is_string($parsed['host'] ?? null) ? $parsed['host'] : '';
        $scheme = is_array($parsed) && is_string($parsed['scheme'] ?? null)
            ? strtolower($parsed['scheme'])
            : '';
        if ($scheme === '') {
            throw new AccessDeniedException('outbound request (missing URL scheme)', 'network');
        }
        $this->assertOutboundTargetAllowed($host, $scheme);
    }

    private function assertOutboundTargetAllowed(string $host, ?string $scheme): void
    {
        if (!$this->isEnforced()) {
            return;
        }
        // Local-mode escape hatch: in DDEV (or with localUnsafeMode=on) the
        // manifest's network.outbound list is bypassed so workflows like
        // "fetch this Unsplash image" work without operators having to edit
        // Capabilities.yaml every session. Production sites with the default
        // localUnsafeMode=auto resolve to false here and the strict policy
        // applies.
        if ($this->localMode !== null && $this->localMode->allowsUnrestrictedOutbound()) {
            return;
        }
        if ($host === '') {
            throw new AccessDeniedException('outbound request (empty host)', 'network');
        }

        $hostLower = strtolower($host);
        foreach ($this->getNetworkOutboundRules() as $rule) {
            if ($scheme !== null && $rule['protocol'] !== null && $rule['protocol'] !== $scheme) {
                continue;
            }
            $entry = $rule['host'];
            $hostMatches = $entry === '*'
                || strtolower($entry) === $hostLower
                || ($entry === 'self' && $this->matchesAnySiteHost($hostLower))
                || (str_starts_with($entry, '*.') && str_ends_with($hostLower, strtolower(substr($entry, 1))));
            if ($hostMatches) {
                return;
            }
        }

        throw new AccessDeniedException(
            sprintf(
                'outbound request to "%s%s" (not in capability manifest network.outbound)',
                $scheme !== null ? $scheme . '://' : '',
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
        return array_values(array_unique(array_column($this->getNetworkOutboundRules(), 'host')));
    }

    /**
     * @return list<array{host: string, protocol: string|null}>
     */
    private function getNetworkOutboundRules(): array
    {
        $capabilities = $this->getCapabilities();
        $network = is_array($capabilities['network'] ?? null) ? $capabilities['network'] : [];
        $outbound = $network['outbound'] ?? [];

        // The public schema represents unrestricted outbound access as the
        // scalar `any`; the runtime historically used `*`.
        if ($outbound === 'any' || $outbound === '*') {
            return [['host' => '*', 'protocol' => null]];
        }
        if (!is_array($outbound)) {
            return [];
        }

        $rules = [];
        foreach ($outbound as $entry) {
            // String entries are accepted for backward compatibility with
            // pre-schema manifests. New manifests use {host, purpose, ...}.
            $host = is_string($entry)
                ? $entry
                : (is_array($entry) && is_string($entry['host'] ?? null) ? $entry['host'] : '');
            $host = trim($host);
            if ($host === '') {
                continue;
            }
            $protocol = is_array($entry) && is_string($entry['protocol'] ?? null)
                ? strtolower(trim($entry['protocol']))
                : null;
            $rules[] = [
                'host' => $host === 'any' ? '*' : $host,
                'protocol' => $protocol !== '' ? $protocol : null,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function getCapabilities(): array
    {
        $manifest = $this->getManifest();
        $capabilities = $manifest['capabilities'] ?? null;
        return is_array($capabilities) ? $this->normalizeStringKeyedMap($capabilities) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMcpExtension(): array
    {
        $mcp = $this->getCapabilities()['x-mcp'] ?? [];
        return is_array($mcp) ? $this->normalizeStringKeyedMap($mcp) : [];
    }

    /**
     * Native tools require matching CLI projections. Explicitly trusted
     * third-party adapters live in `external_tools`, so installing another
     * package can never silently expand the MCP attack surface.
     *
     * @return array<string, mixed>
     */
    private function getToolPolicyDefinitions(): array
    {
        $capabilities = $this->getCapabilities();
        $mcp = $this->getMcpExtension();
        // See getRequiresMap(): new manifests use x-mcp, old installations
        // may still carry the native map directly under capabilities.
        $native = array_key_exists('tools', $mcp)
            ? $mcp['tools']
            : ($capabilities['tools'] ?? []);
        $external = $mcp['external_tools'] ?? [];

        $native = is_array($native) ? $this->normalizeStringKeyedMap($native) : [];
        $external = is_array($external) ? $this->normalizeStringKeyedMap($external) : [];

        // Native policy is authoritative if a third-party package attempts
        // to register a colliding name.
        return $native + $external;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * YAML maps can technically contain integer keys. Public service methods
     * deliberately expose only named manifest fields.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedMap(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $key !== '') {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getMcpDefinitions(string $key): array
    {
        $definitions = $this->getMcpExtension()[$key] ?? [];
        if (!is_array($definitions)) {
            return [];
        }

        $normalized = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                continue;
            }
            $normalized[$name] = $this->normalizeStringKeyedMap($definition);
        }
        return $normalized;
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
            return $value !== 0;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
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
        if ($this->manifestPathOverride !== null && is_file($this->manifestPathOverride)) {
            return $this->manifestPathOverride;
        }
        // Prefer the extension folder path resolved by TYPO3 (handles both
        // composer and TER installations).
        $candidate = null;
        try {
            $candidate = ExtensionManagementUtility::extPath('mcp_server') . self::MANIFEST_PATH;
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
