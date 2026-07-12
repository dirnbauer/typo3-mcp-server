<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;

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
}
