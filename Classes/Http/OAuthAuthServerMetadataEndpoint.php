<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * OAuth Authorization Server Metadata endpoint
 * RFC 8414: https://tools.ietf.org/html/rfc8414
 */
final readonly class OAuthAuthServerMetadataEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private OAuthService $oauthService,
        private SiteBaseUrlResolver $baseUrlResolver,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $corsRejection = $this->rejectDisallowedCorsRequest($request);
        if ($corsRejection instanceof ResponseInterface) {
            return $corsRejection;
        }

        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }

        $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);

        $metadata = $this->oauthService->getMetadata($baseUrl);

        return $this->createJsonResponse($metadata, $request);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonResponse(array $data, ServerRequestInterface $request): ResponseInterface
    {
        $response = new JsonResponse($data);

        // Add CORS headers
        $response = $this->addCorsHeaders($response, $request);

        // Add cache headers (short cache for dynamic content)
        $response = $response
            ->withHeader('Cache-Control', 'public, max-age=300') // 5 minutes
            ->withHeader('Vary', 'Origin');

        return $this->addSecurityHeaders($response);
    }
}
