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

        if ($allowedOrigin === null) {
            return $response;
        }

        // Streamable HTTP MCP clients (e.g. Cursor) send Mcp-Session-Id and MCP-Protocol-Version.
        // Browsers omit disallowed headers on cross-origin requests unless listed here, which
        // breaks session continuity and yields "connected" with zero tools.
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, DELETE')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Accept, Authorization, Content-Type, X-Requested-With, '
                . 'Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID'
            )
            ->withHeader(
                'Access-Control-Expose-Headers',
                'Mcp-Session-Id, MCP-Protocol-Version, Content-Type'
            )
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Max-Age', '86400'); // Cache preflight for 24 hours
    }

    /**
     * Get the allowed origin from the request
     */
    private function getAllowedOrigin(): ?string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        if ($request->hasHeader('Origin')) {
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '') {
                return $origin;
            }
        }

        return null;
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
