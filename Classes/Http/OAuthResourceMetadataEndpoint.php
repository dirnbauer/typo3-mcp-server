<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * OAuth Resource Server Metadata endpoint
 * RFC 9728: https://www.rfc-editor.org/rfc/rfc9728.html
 */
final class OAuthResourceMetadataEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private SiteBaseUrlResolver $baseUrlResolver,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $corsRejection = $this->rejectDisallowedCorsRequest($request);
        if ($corsRejection instanceof ResponseInterface) {
            return $corsRejection;
        }
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }

        // Get base URL from request
        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);

        $metadata = [
            'resource' => $baseUrl . '/mcp',
            'authorization_servers' => [
                $baseUrl,
            ],
            'bearer_methods_supported' => [
                'header',
            ],
            'scopes_supported' => [
                OAuthService::DEFAULT_SCOPE,
            ],
            'resource_documentation' => $baseUrl . '/typo3/module/user/mcp-server',
        ];

        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $stream->rewind();

        $response = new Response(
            $stream,
            200,
            [
                'Content-Type' => 'application/json',
            ],
        );

        return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
    }
}
