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
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $baseUrl .= ':' . $port;
        }

        return rtrim($baseUrl, '/');
    }

    public function hasConfiguredBaseUrl(): bool
    {
        return $this->getConfiguredBaseUrl() !== null;
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
