<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * OAuth dynamic client registration endpoint (RFC 7591).
 */
final readonly class OAuthRegisterEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private OAuthService $oauthService,
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
            // Only accept POST requests
            if ($request->getMethod() !== 'POST') {
                return $this->finalizeResponse(
                    $this->createErrorResponse('invalid_request', 'Method not allowed', 405),
                    $request,
                );
            }

            // Read at most one byte beyond the limit. This also protects
            // chunked requests whose Content-Length is absent or dishonest.
            $body = $this->readBoundedBody($request, 65536);
            if ($body === null) {
                return $this->finalizeResponse(
                    $this->createErrorResponse('invalid_client_metadata', 'Registration payload exceeds 64 KiB'),
                    $request,
                );
            }
            $clientData = json_decode($body, true);

            if (!is_array($clientData)) {
                return $this->finalizeResponse(
                    $this->createErrorResponse('invalid_request', 'Invalid JSON in request body'),
                    $request,
                );
            }

            $normalizedClientData = [];
            foreach ($clientData as $key => $value) {
                if (is_string($key)) {
                    $normalizedClientData[$key] = $value;
                }
            }
            $clientInfo = $this->oauthService->registerClient($normalizedClientData);

            // Return client registration response
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson($clientInfo));
            $stream->rewind();

            $response = new Response(
                $stream,
                201, // Created
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-store',
                    'Pragma' => 'no-cache',
                ],
            );

            return $this->finalizeResponse($response, $request);

        } catch (\InvalidArgumentException $e) {
            return $this->finalizeResponse(
                $this->createErrorResponse('invalid_client_metadata', $e->getMessage()),
                $request,
            );
        } catch (\Throwable $e) {
            $this->logger->error('OAuth client registration failed', ['exception' => $e]);

            return $this->finalizeResponse(
                $this->createErrorResponse('server_error', 'Unable to register the client right now.', 500),
                $request,
            );
        }
    }

    private function createErrorResponse(string $error, string $description = '', int $statusCode = 400): ResponseInterface
    {
        $errorData = [
            'error' => $error,
            'error_description' => $description,
        ];

        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->encodeJson($errorData));
        $stream->rewind();

        $response = new Response(
            $stream,
            $statusCode,
            ['Content-Type' => 'application/json'],
        );

        return $response;
    }

    private function finalizeResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
    }

    private function readBoundedBody(ServerRequestInterface $request, int $maximumBytes): ?string
    {
        $contentLength = trim($request->getHeaderLine('Content-Length'));
        if ($contentLength !== '' && ctype_digit($contentLength) && (int)$contentLength > $maximumBytes) {
            return null;
        }

        $stream = $request->getBody();
        $body = '';
        while (!$stream->eof() && strlen($body) <= $maximumBytes) {
            $remaining = ($maximumBytes + 1) - strlen($body);
            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return strlen($body) > $maximumBytes || !$stream->eof() ? null : $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        $json = json_encode($data);
        return is_string($json) ? $json : '{}';
    }
}
