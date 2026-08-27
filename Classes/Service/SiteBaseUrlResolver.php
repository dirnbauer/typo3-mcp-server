<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Resolves the public site base URL from TYPO3 configuration or the incoming request.
 */
final readonly class SiteBaseUrlResolver
{
    public function resolveFromRequest(ServerRequestInterface $request): string
    {
        $configured = $this->getConfiguredBaseUrl();
        if ($configured !== null) {
            return $configured;
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return rtrim($normalizedParams->getSiteUrl(), '/');
        }

        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $baseUrl = $scheme . '://' . $host;
        $port = $uri->getPort();
        $isDefaultPort = ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
        if ($port !== null && !$isDefaultPort) {
            $baseUrl .= ':' . $port;
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * TYPO3 installation path without a trailing slash, or an empty string
     * for an installation at the origin root.
     */
    public function resolveSitePathFromRequest(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $this->normalizeSitePath($normalizedParams->getSitePath());
        }

        $configured = $this->getConfiguredBaseUrl();
        if ($configured === null) {
            return '';
        }

        $path = parse_url($configured, PHP_URL_PATH);

        return is_string($path) ? $this->normalizeSitePath($path) : '';
    }

    /**
     * Normalize an incoming path to the application-relative route used by
     * MCP/OAuth dispatch, preserving root installations unchanged.
     */
    public function resolveApplicationRoutePath(ServerRequestInterface $request): string
    {
        $path = $this->normalizeRoutePath($request->getUri()->getPath());
        $sitePath = $this->resolveSitePathFromRequest($request);
        if ($sitePath === '') {
            return $path;
        }
        if ($path === $sitePath) {
            return '/';
        }
        if (str_starts_with($path, $sitePath . '/')) {
            return substr($path, strlen($sitePath));
        }

        return $path;
    }

    public function resolveOriginFromRequest(ServerRequestInterface $request): string
    {
        $baseUrl = $this->resolveFromRequest($request);
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('Unable to resolve the public MCP origin.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            throw new \RuntimeException('The public MCP base URL must include a scheme and host.');
        }
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }

        return $origin;
    }

    /** RFC 9728 metadata URL for the protected MCP resource. */
    public function resolveProtectedResourceMetadataUrl(ServerRequestInterface $request): string
    {
        return $this->resolveOriginFromRequest($request)
            . '/.well-known/oauth-protected-resource'
            . $this->resolveSitePathFromRequest($request)
            . '/mcp';
    }

    /** RFC 8414 metadata URL for an authorization-server issuer with a path. */
    public function resolveAuthorizationServerMetadataUrl(ServerRequestInterface $request): string
    {
        return $this->resolveOriginFromRequest($request)
            . '/.well-known/oauth-authorization-server'
            . $this->resolveSitePathFromRequest($request);
    }

    public function isProtectedResourceMetadataPath(ServerRequestInterface $request): bool
    {
        $path = parse_url($this->resolveProtectedResourceMetadataUrl($request), PHP_URL_PATH);

        return is_string($path) && $this->normalizeRoutePath($request->getUri()->getPath()) === $path;
    }

    public function isAuthorizationServerMetadataPath(ServerRequestInterface $request): bool
    {
        $path = parse_url($this->resolveAuthorizationServerMetadataUrl($request), PHP_URL_PATH);

        return is_string($path) && $this->normalizeRoutePath($request->getUri()->getPath()) === $path;
    }

    public function hasConfiguredBaseUrl(): bool
    {
        return $this->getConfiguredBaseUrl() !== null;
    }

    public function resolveConfiguredOrPlaceholder(string $placeholder = 'https://your-domain.com'): string
    {
        return $this->getConfiguredBaseUrl() ?? $placeholder;
    }

    public function getConfiguredBaseUrl(): ?string
    {
        /** @var mixed $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $configured = is_array($confVars) && is_array($confVars['SYS'] ?? null)
            ? ($confVars['SYS']['reverseProxyBaseUrl'] ?? null)
            : null;

        if (!is_string($configured) || $configured === '') {
            return null;
        }

        return rtrim($configured, '/');
    }

    private function normalizeSitePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    private function normalizeRoutePath(string $path): string
    {
        return $path === '/' ? $path : rtrim($path, '/');
    }
}
