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
 * OAuth token endpoint for exchanging authorization codes for access tokens
 */
final readonly class OAuthTokenEndpoint
{
    use CorsHeadersTrait;

    public function __construct(
        private LoggerInterface $logger,
        private OAuthService $oauthService,
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

            $parsedBody = $this->getParsedBodyArray($request);

            // Extract parameters (support both form data and JSON)
            $grantType = $parsedBody['grant_type'] ?? '';
            $code = $parsedBody['code'] ?? '';
            $clientId = $parsedBody['client_id'] ?? '';
            $codeVerifier = $parsedBody['code_verifier'] ?? null;

            // Validate required parameters
            if ($grantType !== 'authorization_code') {
                return $this->createErrorResponse('unsupported_grant_type', 'Only authorization_code grant type is supported');
            }

            if (empty($code)) {
                return $this->createErrorResponse('invalid_request', 'Missing required parameter: code');
            }

            if (empty($clientId) || $clientId !== 'typo3-mcp-server') {
                return $this->createErrorResponse('invalid_client', 'Invalid client_id');
            }

            $tokenData = $this->oauthService->exchangeCodeForToken($code, $codeVerifier, $request);

            if (!$tokenData) {
                return $this->createErrorResponse('invalid_grant', 'Invalid or expired authorization code');
            }

            $this->logger->debug('Token exchange successful', [
                'tokenPrefix' => substr($tokenData['access_token'], 0, 20),
            ]);

            // Return token response
            $stream = new Stream('php://temp', 'rw');
            $stream->write($this->encodeJson($tokenData));
            $stream->rewind();

            $response = new Response(
                $stream,
                200,
                ['Content-Type' => 'application/json'],
            );

            return $this->addCorsHeaders($response);

        } catch (\Throwable $e) {
            $this->logger->error('OAuth token exchange failed', ['exception' => $e]);
            return $this->createErrorResponse('server_error', 'Token exchange failed', 500);
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
     * @return array<string, string|null>
     */
    private function getParsedBodyArray(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (!\is_array($parsedBody)) {
            return [];
        }

        $result = [];
        foreach ($parsedBody as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\is_string($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
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
