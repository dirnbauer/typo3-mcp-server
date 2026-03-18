<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
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
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        try {
            // Get base URL from request
            $uri = $request->getUri();
            $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
            if ($uri->getPort() && !\in_array($uri->getPort(), [80, 443])) {
                $baseUrl .= ':' . $uri->getPort();
            }

            // Override base URL for development if needed
            /** @var mixed $confVars */
            $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
            $configuredBaseUrl = \is_array($confVars) && \is_array($confVars['SYS'] ?? null)
                ? ($confVars['SYS']['reverseProxyBaseUrl'] ?? null)
                : null;
            if (\is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
                $baseUrl = rtrim($configuredBaseUrl, '/');
            }

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
        return \is_string($json) ? $json : '{}';
    }
}
