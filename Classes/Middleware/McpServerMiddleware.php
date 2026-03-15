<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use TYPO3\CMS\Core\Crypto\HashAlgo;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Http\OAuthAuthServerMetadataEndpoint;
use Hn\McpServer\Http\OAuthMetadataEndpoint;
use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unified PSR-15 Middleware to handle all MCP Server routes
 * Provides clean URLs for MCP protocol, OAuth flow, and discovery endpoints
 */
final readonly class McpServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Context $context,
        private HashService $hashService,
        private McpEndpoint $mcpEndpoint,
        private OAuthAuthorizeEndpoint $oauthAuthorizeEndpoint,
        private OAuthTokenEndpoint $oauthTokenEndpoint,
        private OAuthMetadataEndpoint $oauthMetadataEndpoint,
        private OAuthRegisterEndpoint $oauthRegisterEndpoint,
        private OAuthResourceMetadataEndpoint $oauthResourceMetadataEndpoint,
        private OAuthAuthServerMetadataEndpoint $oauthAuthServerMetadataEndpoint,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        return match ($path) {
            '/mcp' => ($this->mcpEndpoint)($request),

            '/mcp_oauth/authorize' => ($this->oauthAuthorizeEndpoint)($request),
            '/mcp_oauth/token' => ($this->oauthTokenEndpoint)($request),
            '/mcp_oauth/metadata' => ($this->oauthMetadataEndpoint)($request),
            '/mcp_oauth/register' => ($this->oauthRegisterEndpoint)($request),
            '/mcp_oauth/resource' => ($this->oauthResourceMetadataEndpoint)($request),

            '/.well-known/oauth-authorization-server' => ($this->oauthAuthServerMetadataEndpoint)($request),
            '/.well-known/oauth-protected-resource' => ($this->oauthResourceMetadataEndpoint)($request),

            '/typo3/main' => $this->handleOAuthCookieContinuation($request, $handler),

            default => $handler->handle($request),
        };
    }

    /**
     * Handle OAuth cookie continuation after login
     */
    private function handleOAuthCookieContinuation(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();

        // Check if OAuth cookie exists
        if (!isset($cookies['tx_mcpserver_oauth'])) {
            return $handler->handle($request); // No OAuth cookie, handle normally
        }

        $cookieValue = $cookies['tx_mcpserver_oauth'];

        // Decode and validate OAuth data
        $oauthData = $this->decodeOAuthCookie($cookieValue);
        if ($oauthData === null) {
            return $handler->handle($request); // Invalid data, continue normal flow
        }

        // Check if user is now authenticated using Context API
        /** @var UserAspect $backendUserAspect */
        $backendUserAspect = $this->context->getAspect('backend.user');
        if (!$backendUserAspect->isLoggedIn()) {
            return $handler->handle($request); // User still not authenticated, continue normal flow
        }

        // User is authenticated, redirect back to OAuth authorization endpoint
        $queryParams = http_build_query([
            'client_id' => $oauthData['client_id'] ?? '',
            'client_name' => $oauthData['client_name'] ?? '',
            'redirect_uri' => $oauthData['redirect_uri'] ?? '',
            'code_challenge' => $oauthData['code_challenge'] ?? '',
            'code_challenge_method' => $oauthData['code_challenge_method'] ?? '',
            'state' => $oauthData['state'] ?? '',
        ]);

        $oauthAuthorizeUrl = '/mcp_oauth/authorize?' . $queryParams;

        $stream = new Stream('php://temp', 'rw');
        $stream->write('');
        $stream->rewind();

        return new Response(
            $stream,
            302,
            ['Location' => $oauthAuthorizeUrl],
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function decodeOAuthCookie(string $cookieValue): ?array
    {
        $parts = explode('.', $cookieValue, 2);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $payload = base64_decode($parts[0], true);
        if (!\is_string($payload) || $payload === '') {
            return null;
        }

        $expectedSignature = $this->hashService->hmac($payload, 'mcpserver-oauth', HashAlgo::SHA3_256);
        if (!hash_equals($expectedSignature, $parts[1])) {
            return null;
        }

        $oauthData = json_decode($payload, true);
        if (!\is_array($oauthData)) {
            return null;
        }

        $result = [];
        foreach ($oauthData as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
