<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Mcp\Shared\McpHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * Shared CORS and browser-response hardening for MCP and OAuth endpoints.
 */
trait CorsHeadersTrait
{
    /** @var list<string> */
    private const CORS_ALLOWED_REQUEST_HEADERS = [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'Mcp-Session-Id',
        'MCP-Protocol-Version',
        'Last-Event-ID',
        'Mcp-Method',
        'Mcp-Name',
    ];

    /** @var list<string> */
    private const CORS_EXPOSED_RESPONSE_HEADERS = [
        'Mcp-Session-Id',
        'MCP-Protocol-Version',
        'Mcp-Method',
        'Mcp-Name',
        'Content-Type',
    ];

    /**
     * Add credentialed CORS headers only for the request's own origin or an
     * administrator-configured exact origin. A supplied, disallowed Origin is
     * a protocol-level rejection rather than a response without CORS headers.
     */
    private function addCorsHeaders(
        ResponseInterface $response,
        ?ServerRequestInterface $request = null,
    ): ResponseInterface {
        $request ??= $this->getCurrentCorsRequest();
        if (!$request instanceof ServerRequestInterface) {
            return $response;
        }

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return $response;
        }

        if (!$this->isCorsOriginAllowed($request, $origin)) {
            return $this->createCorsForbiddenResponse();
        }

        $requestedHeaders = $this->getRequestedCorsHeaders($request);
        if ($requestedHeaders === null) {
            return $this->createCorsForbiddenResponse('A requested CORS header is not allowed');
        }

        $allowedHeaders = array_values(array_unique([
            ...self::CORS_ALLOWED_REQUEST_HEADERS,
            ...$requestedHeaders,
        ]));
        $exposedHeaders = array_values(array_unique([
            ...self::CORS_EXPOSED_RESPONSE_HEADERS,
            ...$requestedHeaders,
        ]));

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, DELETE')
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $allowedHeaders))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', $exposedHeaders))
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin, Access-Control-Request-Headers')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    /**
     * Reject a cross-origin request before authentication or MCP dispatch.
     */
    private function rejectDisallowedCorsRequest(ServerRequestInterface $request): ?ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '' || $this->isCorsOriginAllowed($request, $origin)) {
            return null;
        }

        return $this->createCorsForbiddenResponse();
    }

    /**
     * Hostnames passed to the SDK's independent DNS-rebinding guard.
     *
     * @return list<string>
     */
    private function getAllowedCorsHostnames(ServerRequestInterface $request): array
    {
        $hosts = [];
        $requestHost = strtolower(trim($request->getUri()->getHost(), '[]'));
        if ($requestHost !== '') {
            $hosts[] = $requestHost;
        }

        foreach ($this->getConfiguredCorsOrigins() as $origin) {
            $host = parse_url((string)$origin, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower(trim($host, '[]'));
            }
        }

        return array_values(array_unique($hosts));
    }

    private function isCorsOriginAllowed(ServerRequestInterface $request, string $origin): bool
    {
        $normalizedOrigin = $this->normalizeCorsOrigin($origin);
        if ($normalizedOrigin === null) {
            return false;
        }

        $requestOrigin = $this->normalizeCorsOrigin($this->requestOrigin($request));
        if ($requestOrigin !== null && hash_equals($requestOrigin, $normalizedOrigin)) {
            return true;
        }

        foreach ($this->getConfiguredCorsOrigins() as $configuredOrigin) {
            if (hash_equals($configuredOrigin, $normalizedOrigin)) {
                return true;
            }
        }

        return false;
    }

    private function requestOrigin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $origin = strtolower($uri->getScheme()) . '://' . $host;
        if ($uri->getPort() !== null) {
            $origin .= ':' . $uri->getPort();
        }

        return $origin;
    }

    /** @return list<string> */
    private function getConfiguredCorsOrigins(): array
    {
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($typo3Configuration)) {
            return [];
        }
        $extensionConfiguration = $typo3Configuration['EXTENSIONS'] ?? null;
        if (!is_array($extensionConfiguration)) {
            return [];
        }
        $configuration = $extensionConfiguration['mcp_server'] ?? null;
        if (!is_array($configuration)) {
            return [];
        }

        $configuredValue = $configuration['allowedOrigins'] ?? '';
        if (!is_string($configuredValue) || trim($configuredValue) === '') {
            return [];
        }

        $origins = [];
        $configuredOrigins = preg_split('/\s*,\s*/', trim($configuredValue));
        if ($configuredOrigins === false) {
            $configuredOrigins = [];
        }
        foreach ($configuredOrigins as $origin) {
            $normalized = $this->normalizeCorsOrigin($origin, true);
            if ($normalized !== null) {
                $origins[] = $normalized;
            }
        }

        return array_values(array_unique($origins));
    }

    private function normalizeCorsOrigin(string $origin, bool $allowTrailingSlash = false): ?string
    {
        if ($origin === '' || str_contains($origin, ',')) {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = (string)($parts['path'] ?? '');
        if ($path !== '' && (!$allowTrailingSlash || $path !== '/')) {
            return null;
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Return validated dynamic Mcp-Param-* request headers. Unknown preflight
     * headers are rejected, and only RFC 9110 token names may be reflected.
     *
     * @return list<string>|null
     */
    private function getRequestedCorsHeaders(ServerRequestInterface $request): ?array
    {
        $requestedHeaderLine = trim($request->getHeaderLine('Access-Control-Request-Headers'));
        if ($requestedHeaderLine === '') {
            return [];
        }

        $knownHeaders = array_fill_keys(array_map(strtolower(...), self::CORS_ALLOWED_REQUEST_HEADERS), true);
        $dynamicHeaders = [];
        foreach (explode(',', $requestedHeaderLine) as $requestedHeader) {
            $requestedHeader = trim($requestedHeader);
            $lowerHeader = strtolower($requestedHeader);
            if ($requestedHeader === '' || isset($knownHeaders[$lowerHeader])) {
                continue;
            }
            if (!str_starts_with($lowerHeader, 'mcp-param-')) {
                return null;
            }

            $suffix = substr($requestedHeader, strlen('Mcp-Param-'));
            if ($suffix === '' || strlen($suffix) > 128 || !McpHeaders::isValidAnnotationName($suffix)) {
                return null;
            }
            $dynamicHeaders[] = 'Mcp-Param-' . $suffix;
        }

        return array_values(array_unique($dynamicHeaders));
    }

    private function getCurrentCorsRequest(): ?ServerRequestInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * Handle preflight OPTIONS requests.
     */
    private function handlePreflightRequest(?ServerRequestInterface $request = null): ResponseInterface
    {
        $response = $this->addCorsHeaders((new Response())->withStatus(200), $request);
        return $this->addSecurityHeaders($response);
    }

    private function createCorsForbiddenResponse(string $message = 'Origin is not allowed'): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode([
            'error' => 'Forbidden',
            'message' => $message,
        ], JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $this->addSecurityHeaders(new Response(
            $stream,
            403,
            ['Content-Type' => 'application/json'],
        ));
    }

    /**
     * Stamp browser-defense headers onto an MCP / OAuth response.
     */
    private function addSecurityHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
