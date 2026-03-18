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
 * OAuth Dynamic Client Registration endpoint
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
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        try {
            // Only accept POST requests
            if ($request->getMethod() !== 'POST') {
                return $this->createErrorResponse('invalid_request', 'Method not allowed', 405);
            }

            // Get request body
            $body = $request->getBody()->getContents();
            $clientData = json_decode($body, true);

            if (!\is_array($clientData)) {
                return $this->createErrorResponse('invalid_request', 'Invalid JSON in request body');
            }

            // Validate required fields (minimal validation for MCP)
            if (!isset($clientData['client_name']) || !\is_string($clientData['client_name']) || $clientData['client_name'] === '') {
                $clientData['client_name'] = 'MCP Client';
            }

            // redirect_uris is required by RFC 7591, but for MCP clients we can provide a default
            if (empty($clientData['redirect_uris']) || !\is_array($clientData['redirect_uris'])) {
                $clientData['redirect_uris'] = ['http://localhost']; // Default for local MCP clients
            }

            // Set default values for MCP clients
            $clientData['grant_types'] ??= ['authorization_code'];
            $clientData['response_types'] ??= ['code'];
            $clientData['scope'] ??= 'mcp_access';
            /** @var array{client_name: string, redirect_uris: list<string>, grant_types: list<string>, response_types: list<string>, scope: string} $clientData */
            $clientInfo = $this->oauthService->registerClient($clientData);

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

            return $this->addCorsHeaders($response);

        } catch (\Throwable $e) {
            $this->logger->error('OAuth client registration failed', ['exception' => $e]);

            return $this->createErrorResponse('server_error', 'Unable to register the client right now.', 500);
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

        return $this->addCorsHeaders($response);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encodeJson(array $data): string
    {
        $json = json_encode($data);
        return \is_string($json) ? $json : '{}';
    }
}
