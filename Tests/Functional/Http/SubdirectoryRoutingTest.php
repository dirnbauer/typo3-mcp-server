<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SubdirectoryRoutingTest extends AbstractFunctionalTest
{
    #[Test]
    public function routesApplicationRelativeProtectedResourceMetadata(): void
    {
        $request = $this->createRequest('/subfolder/.well-known/oauth-protected-resource/mcp');
        $response = $this->middleware()->process($request, $this->sentinelHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'https://example.com/subfolder/mcp',
            $this->decodeResponse($response)['resource'] ?? null,
        );
    }

    #[Test]
    public function routesRfcProtectedResourceMetadataAtOriginRoot(): void
    {
        $request = $this->createRequest('/.well-known/oauth-protected-resource/subfolder/mcp');
        $response = $this->middleware()->process($request, $this->sentinelHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'https://example.com/subfolder/mcp',
            $this->decodeResponse($response)['resource'] ?? null,
        );
    }

    #[Test]
    public function routesRfcAuthorizationServerMetadataForIssuerPath(): void
    {
        $request = $this->createRequest('/.well-known/oauth-authorization-server/subfolder');
        $response = $this->middleware()->process($request, $this->sentinelHandler());
        $metadata = $this->decodeResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com/subfolder', $metadata['issuer'] ?? null);
        self::assertSame(
            'https://example.com/subfolder/mcp_oauth/token',
            $metadata['token_endpoint'] ?? null,
        );
    }

    #[Test]
    public function leavesUnrelatedSubdirectoryPathsForTheNextHandler(): void
    {
        $request = $this->createRequest('/subfolder/some/regular/page');

        self::assertSame(418, $this->middleware()->process($request, $this->sentinelHandler())->getStatusCode());
    }

    private function middleware(): McpServerMiddleware
    {
        return GeneralUtility::makeInstance(McpServerMiddleware::class);
    }

    private function createRequest(string $requestPath): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/subfolder/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/subfolder/index.php',
            'REQUEST_URI' => $requestPath,
        ];

        return (new ServerRequest(
            new Uri('https://example.com' . $requestPath),
            'GET',
            'php://input',
            [],
            $serverParams,
        ))->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function sentinelHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(418);
            }
        };
    }
}
