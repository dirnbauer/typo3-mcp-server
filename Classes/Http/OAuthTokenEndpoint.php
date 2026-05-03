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

            $grantType = $parsedBody['grant_type'] ?? '';
            $clientId = $parsedBody['client_id'] ?? '';

            if ($grantType !== 'authorization_code' && $grantType !== 'refresh_token') {
                return $this->createErrorResponse('unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token');
            }

            if (!$this->isValidClientId($clientId)) {
                return $this->createErrorResponse('invalid_client', 'Invalid client_id');
            }

            if ($grantType === 'refresh_token') {
                return $this->handleRefreshTokenGrant($parsedBody, $request);
            }

            return $this->handleAuthorizationCodeGrant($parsedBody, $request);

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
     * @param array<string, string|null> $parsedBody
     */
    private function handleAuthorizationCodeGrant(array $parsedBody, ServerRequestInterface $request): ResponseInterface
    {
        $code = $parsedBody['code'] ?? '';
        $codeVerifier = $parsedBody['code_verifier'] ?? null;

        if ($code === '') {
            return $this->createErrorResponse('invalid_request', 'Missing required parameter: code');
        }

        $tokenData = $this->oauthService->exchangeCodeForToken($code, $codeVerifier, $request);

        if (!$tokenData) {
            return $this->createErrorResponse('invalid_grant', 'Invalid or expired authorization code');
        }

        // Log only an 8-char prefix (32 bits) — enough to correlate logs,
        // not enough to brute-force back to the full 64-char hex token.
        $this->logger->debug('Token exchange successful', [
            'tokenPrefix' => substr($tokenData['access_token'], 0, 8),
        ]);

        return $this->createTokenResponse($tokenData);
    }

    /**
     * @param array<string, string|null> $parsedBody
     */
    private function handleRefreshTokenGrant(array $parsedBody, ServerRequestInterface $request): ResponseInterface
    {
        $refreshToken = $parsedBody['refresh_token'] ?? '';

        if ($refreshToken === '') {
            return $this->createErrorResponse('invalid_request', 'Missing required parameter: refresh_token');
        }

        $tokenData = $this->oauthService->refreshAccessToken($refreshToken, $request);

        if (!$tokenData) {
            return $this->createErrorResponse('invalid_grant', 'Invalid or expired refresh token');
        }

        $this->logger->debug('Token refresh successful', [
            'tokenPrefix' => substr($tokenData['access_token'], 0, 8),
        ]);

        return $this->createTokenResponse($tokenData);
    }

    /**
     * @param array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $tokenData
     */
    private function createTokenResponse(array $tokenData): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->encodeJson($tokenData));
        $stream->rewind();

        $response = new Response(
            $stream,
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ],
        );

        return $this->addCorsHeaders($response);
    }

    private function isValidClientId(?string $clientId): bool
    {
        return $clientId === 'typo3-mcp-server';
    }

    /**
     * @return array<string, string|null>
     */
    private function getParsedBodyArray(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            $parsedBody = $this->parseRawBody($request);
        }

        $result = [];
        foreach ($parsedBody as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value) || $value === null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseRawBody(ServerRequestInterface $request): array
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $rawBody = $body->getContents();

        if ($rawBody === '') {
            return [];
        }

        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $formData);
            return $formData;
        }

        return [];
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
