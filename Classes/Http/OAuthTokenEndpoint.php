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

    private const MAX_REQUEST_BODY_BYTES = 65536;

    public function __construct(
        private LoggerInterface $logger,
        private OAuthService $oauthService,
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
                $response = $this->createErrorResponse('invalid_request', 'Method not allowed', 405);
                return $this->finalizeResponse($response, $request);
            }

            try {
                $parsedBody = $this->getParsedBodyArray($request);
            } catch (\LengthException) {
                return $this->finalizeResponse(
                    $this->createErrorResponse('invalid_request', 'Token request payload exceeds 64 KiB', 413),
                    $request,
                );
            }

            $grantType = $parsedBody['grant_type'] ?? '';
            $clientId = $parsedBody['client_id'] ?? '';

            if ($grantType !== 'authorization_code' && $grantType !== 'refresh_token') {
                $response = $this->createErrorResponse('unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token');
                return $this->finalizeResponse($response, $request);
            }

            $client = $this->oauthService->getRegisteredClient($clientId);
            if ($client === null || !in_array($grantType, $client['grant_types'], true)) {
                $response = $this->createErrorResponse('invalid_client', 'Invalid client_id or grant type');
                return $this->finalizeResponse($response, $request);
            }

            $resource = $parsedBody['resource'] ?? '';
            if ($resource === '') {
                $response = $this->createErrorResponse('invalid_target', 'Missing required parameter: resource');
                return $this->finalizeResponse($response, $request);
            }
            try {
                $this->oauthService->requireCanonicalResourceUri($resource);
            } catch (\InvalidArgumentException) {
                $response = $this->createErrorResponse('invalid_target', 'Invalid resource parameter');
                return $this->finalizeResponse($response, $request);
            }

            if ($grantType === 'refresh_token') {
                $response = $this->handleRefreshTokenGrant($parsedBody, $request);
                return $this->finalizeResponse($response, $request);
            }

            $response = $this->handleAuthorizationCodeGrant($parsedBody, $request);
            return $this->finalizeResponse($response, $request);

        } catch (\Throwable $e) {
            $this->logger->error('OAuth token exchange failed', ['exception' => $e]);
            return $this->finalizeResponse(
                $this->createErrorResponse('server_error', 'Token exchange failed', 500),
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

    /**
     * @param array<string, string|null> $parsedBody
     */
    private function handleAuthorizationCodeGrant(array $parsedBody, ServerRequestInterface $request): ResponseInterface
    {
        $code = $parsedBody['code'] ?? '';
        $codeVerifier = $parsedBody['code_verifier'] ?? null;
        $clientId = $parsedBody['client_id'] ?? '';
        $redirectUri = $parsedBody['redirect_uri'] ?? '';
        $resource = $parsedBody['resource'] ?? '';
        $scope = $parsedBody['scope'] ?? null;

        if ($code === '') {
            return $this->createErrorResponse('invalid_request', 'Missing required parameter: code');
        }

        $tokenData = $this->oauthService->exchangeCodeForToken(
            $code,
            $codeVerifier,
            $request,
            $clientId,
            $redirectUri,
            $resource,
            $scope,
        );

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
        $clientId = $parsedBody['client_id'] ?? '';
        $resource = $parsedBody['resource'] ?? '';
        $scope = $parsedBody['scope'] ?? null;

        if ($refreshToken === '') {
            return $this->createErrorResponse('invalid_request', 'Missing required parameter: refresh_token');
        }

        $tokenData = $this->oauthService->refreshAccessToken(
            $refreshToken,
            $request,
            $clientId,
            $resource,
            $scope,
        );

        if (!$tokenData) {
            return $this->createErrorResponse('invalid_grant', 'Invalid or expired refresh token');
        }

        $this->logger->debug('Token refresh successful', [
            'tokenPrefix' => substr($tokenData['access_token'], 0, 8),
        ]);

        return $this->createTokenResponse($tokenData);
    }

    /**
     * @param array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string} $tokenData
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

        return $response;
    }

    private function finalizeResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $this->addSecurityHeaders($this->addCorsHeaders($response, $request));
    }

    /**
     * @return array<string, string|null>
     */
    private function getParsedBodyArray(ServerRequestInterface $request): array
    {
        $contentLength = trim($request->getHeaderLine('Content-Length'));
        if ($contentLength !== ''
            && ctype_digit($contentLength)
            && (int)$contentLength > self::MAX_REQUEST_BODY_BYTES
        ) {
            throw new \LengthException('OAuth token request body is too large.');
        }

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
        $rawBody = '';
        while (!$body->eof() && strlen($rawBody) <= self::MAX_REQUEST_BODY_BYTES) {
            $remaining = (self::MAX_REQUEST_BODY_BYTES + 1) - strlen($rawBody);
            $chunk = $body->read(min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $rawBody .= $chunk;
        }
        if (strlen($rawBody) > self::MAX_REQUEST_BODY_BYTES || !$body->eof()) {
            throw new \LengthException('OAuth token request body is too large.');
        }

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
