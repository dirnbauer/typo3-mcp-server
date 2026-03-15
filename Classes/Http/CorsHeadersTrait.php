<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;

/**
 * Trait for adding CORS headers to HTTP responses
 */
trait CorsHeadersTrait
{
    /**
     * Add CORS headers to response for OAuth/API endpoints
     */
    private function addCorsHeaders(ResponseInterface $response, ?string $origin = null): ResponseInterface
    {
        $allowedOrigin = $origin ?: $this->getAllowedOrigin();

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Max-Age', '86400'); // Cache preflight for 24 hours
    }

    /**
     * Get the allowed origin from the request
     */
    private function getAllowedOrigin(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface && $request->hasHeader('Origin')) {
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '') {
                return $origin;
            }
        }

        if ($request instanceof ServerRequestInterface) {
            $uri = $request->getUri();
            $origin = $uri->getScheme() . '://' . $uri->getHost();
            $port = $uri->getPort();
            if ($port !== null && !\in_array($port, [80, 443], true)) {
                $origin .= ':' . $port;
            }

            return $origin;
        }

        return 'null';
    }

    /**
     * Handle preflight OPTIONS requests
     */
    private function handlePreflightRequest(): ResponseInterface
    {
        $response = new Response();
        return $this->addCorsHeaders($response->withStatus(200));
    }
}
