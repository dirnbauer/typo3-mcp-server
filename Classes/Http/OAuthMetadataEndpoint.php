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
 * OAuth metadata discovery endpoint
 */
final readonly class OAuthMetadataEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private OAuthService $oauthService,
        private SiteBaseUrlResolver $baseUrlResolver,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        try {
            $baseUrl = $this->baseUrlResolver->resolveFromRequest($request);

            $metadata = $this->oauthService->getMetadata($baseUrl);

            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson($metadata));
            $stream->rewind();

            $response = new Response(
                $stream,
                200,
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
                ],
            );

            return $this->addCorsHeaders($response);

        } catch (\Throwable $e) {
            $errorData = [
                'error' => 'server_error',
                'error_description' => $e->getMessage(),
            ];

            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson($errorData));
            $stream->rewind();

            $response = new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json'],
            );

            return $this->addCorsHeaders($response);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        return is_string($json) ? $json : '{}';
    }
}
