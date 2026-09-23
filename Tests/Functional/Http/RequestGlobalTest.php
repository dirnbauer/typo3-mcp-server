<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The middleware answers /mcp and /mcp_upload itself, so the core
 * RequestHandler that publishes $GLOBALS['TYPO3_REQUEST'] never runs for
 * them. Core APIs behind the tools still read that global: with
 * security.backend.htmlSanitizeRte enabled, DataHandler's RTE sanitizer
 * throws without it, so rich text containing a t3:// link could not be
 * saved over HTTP.
 *
 * Adapted from upstream hauptsacheNet/typo3-mcp-server#129.
 */
final class RequestGlobalTest extends AbstractFunctionalTest
{
    private bool $hadPreviousRequest = false;

    private mixed $previousRequest = null;

    private mixed $previousSanitizeFeature = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadPreviousRequest = array_key_exists('TYPO3_REQUEST', $GLOBALS);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->previousSanitizeFeature = $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] ?? null;
    }

    protected function tearDown(): void
    {
        // Restore an absent global as absent, not as null.
        if ($this->hadPreviousRequest) {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = $this->previousSanitizeFeature;
        parent::tearDown();
    }

    #[Test]
    public function mcpEndpointPublishesTheRequest(): void
    {
        $request = $this->createRequest('/mcp');

        $this->middleware()->process($request, $this->sentinelHandler());

        self::assertSame($request, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }

    #[Test]
    public function uploadEndpointPublishesTheRequest(): void
    {
        $request = $this->createRequest('/mcp_upload');

        $this->middleware()->process($request, $this->sentinelHandler());

        self::assertSame($request, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }

    #[Test]
    public function unrelatedPathLeavesTheRequestUntouched(): void
    {
        $response = $this->middleware()->process($this->createRequest('/some/regular/page'), $this->sentinelHandler());

        self::assertSame(418, $response->getStatusCode(), 'Other paths must reach the next handler');
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS, 'Requests this middleware does not answer stay untouched');
    }

    #[Test]
    public function richTextWithTypo3LinkCanBeSavedOverHttp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.htmlSanitizeRte'] = true;
        $bodytext = '<p><a href="t3://page?uid=1">Home</a></p>';

        $response = $this->callTool('WriteTable', [
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Link over HTTP',
                'bodytext' => $bodytext,
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['result'] ?? null;
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? true, (string)json_encode($result));
        $created = json_decode((string)($result['content'][0]['text'] ?? ''), true);
        self::assertIsArray($created);
        self::assertIsInt($created['uid'] ?? null);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $storedBodytext = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($created['uid'])))
            ->executeQuery()
            ->fetchOne();
        self::assertSame($bodytext, $storedBodytext);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $name, array $arguments): ResponseInterface
    {
        $request = $this->createRequest('/mcp', 'POST');
        $token = $this->getService(OAuthService::class)->createDirectAccessToken(1, 'request-global-test', $request);

        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
                '_meta' => [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'request-global-test', 'version' => '1.0'],
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $body->rewind();

        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', $name)
            ->withBody($body);

        return $this->middleware()->process($request, $this->sentinelHandler());
    }

    private function middleware(): McpServerMiddleware
    {
        return GeneralUtility::makeInstance(McpServerMiddleware::class);
    }

    private function createRequest(string $requestUri, string $method = 'GET'): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => $method,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'REQUEST_URI' => $requestUri,
        ];

        return (new ServerRequest(
            new Uri('https://example.com' . $requestUri),
            $method,
            'php://input',
            [],
            $serverParams,
        ))->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
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
