<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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

            return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));

        } catch (\Throwable $e) {
            $this->logger->error('OAuth metadata generation failed', ['exception' => $e]);
            $errorData = [
                'error' => 'server_error',
                'error_description' => 'OAuth metadata is temporarily unavailable.',
            ];

            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson($errorData));
            $stream->rewind();

            $response = new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json'],
            );

            return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
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
