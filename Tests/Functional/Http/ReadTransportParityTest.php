<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteRequestContext;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\CallToolResult;
use Mcp\Types\MetaKeys;
use Mcp\Types\TextContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The read tools must answer the same over HTTP and over the console
 * transports. Over HTTP the /mcp endpoint answers inside the frontend
 * middleware stack and publishes that request; core file APIs
 * (FileRepository, ResourceFactory, storages, the metadata overlay) switch
 * to frontend behaviour for such a request. The tools see it as a backend
 * request (SiteRequestContext::enterBackendView()).
 *
 * Fixture: content element 100 on page 1 with the nested child 100 of
 * test_nested_files, whose file reference 100 points to test.jpg with the
 * alternative text "Live alternative"; a draft in the MCP workspace changes
 * it to "Draft only". The sandbox file 10 has live metadata "Live metadata"
 * and a draft "Draft metadata" in the same workspace, which is also the
 * user's current workspace.
 */
final class ReadTransportParityTest extends AbstractFunctionalTest
{
    private const string ITEM_TABLE = 'tx_testnestedfiles_item';

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../Fixtures/Extensions/test_nested_files',
        'mcp_server',
    ];

    private int $workspaceId = 0;

    private bool $hadRequest = false;

    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadRequest = array_key_exists('TYPO3_REQUEST', $GLOBALS);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        unset($GLOBALS['TYPO3_REQUEST']);

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file_storage.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $this->getConnectionForTable(self::ITEM_TABLE)->insert(self::ITEM_TABLE, [
            'uid' => 100, 'pid' => 1, 'title' => 'Live item', 'tt_content_items' => 100, 'file' => 1,
        ]);
        $this->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 100, 'pid' => 1, 'uid_local' => 1, 'uid_foreign' => 100,
            'tablenames' => self::ITEM_TABLE, 'fieldname' => 'file', 'alternative' => 'Live alternative',
        ]);
        $this->getConnectionForTable('tt_content')->update('tt_content', [
            'CType' => 'textmedia', 'tx_testnestedfiles_items' => 1,
        ], ['uid' => 100]);

        // Strict mode stages the change in the MCP draft workspace.
        $result = $this->getService(WriteTableTool::class)->execute([
            'action' => 'update', 'table' => 'tt_content', 'uid' => 100,
            'data' => ['tx_testnestedfiles_items' => [['uid' => 100, 'file' => [['uid' => 100, 'alternative' => 'Draft only']]]]],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $this->workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        self::assertGreaterThan(0, $this->workspaceId, 'The draft lives in a workspace');
        unset($GLOBALS['TYPO3_REQUEST']);

        // Tools without a workspace_id parameter read in the user's workspace.
        $this->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => $this->workspaceId], ['uid' => 1]);

        // A sandbox file whose metadata has a draft in the same workspace.
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/mcp');
        file_put_contents(Environment::getPublicPath() . '/fileadmin/mcp/parity.jpg', str_repeat('x', 1000));
        $this->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 10, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'parity.jpg', 'identifier' => '/mcp/parity.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1000,
        ]);
        $metadata = $this->getConnectionForTable('sys_file_metadata');
        $metadata->insert('sys_file_metadata', ['uid' => 10, 'pid' => 0, 'file' => 10, 'alternative' => 'Live metadata']);
        $metadata->insert('sys_file_metadata', [
            'uid' => 11, 'pid' => 0, 'file' => 10, 'alternative' => 'Draft metadata',
            't3ver_oid' => 10, 't3ver_wsid' => $this->workspaceId, 't3ver_state' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->hadRequest) {
            $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        } else {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function readCalls(): iterable
    {
        yield 'ReadTable content element' => ['ReadTable', ['table' => 'tt_content', 'uid' => 100]];
        yield 'ReadTable nested child' => ['ReadTable', ['table' => self::ITEM_TABLE, 'uid' => 100]];
        yield 'GetPage' => ['GetPage', ['uid' => 1]];
        yield 'Search' => ['Search', ['query' => 'Welcome']];
        yield 'SearchMedia' => ['SearchMedia', ['keyword' => 'test']];
        yield 'SearchFile' => ['SearchFile', ['name' => 'parity']];
        yield 'ReadFileMetadata' => ['ReadFileMetadata', ['uid' => 10]];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('readCalls')]
    public function readToolsAnswerTheSameOverHttpAndStdio(string $tool, array $arguments): void
    {
        $arguments['workspace_id'] = $this->workspaceId;

        $overStdio = $this->callOverStdio($tool, $arguments);
        $overHttp = $this->callOverHttp($tool, $arguments);

        // Absolute URLs carry the host of the HTTP request; the console
        // transports have no request host (the fixture has no site).
        self::assertSame($overStdio, preg_replace(['#https://example\\.com/(?=fileadmin/)#', '#https://example\\.com/#'], ['', '/'], $overHttp));
    }

    /**
     * The File API hands out the live metadata record; before 0.9.3 only an
     * HTTP call saw the draft, through the frontend-only metadata overlay.
     */
    #[Test]
    public function theDraftFileMetadataIsReadOnEveryTransport(): void
    {
        foreach (['stdio' => $this->callOverStdio('ReadFileMetadata', ['uid' => 10]), 'http' => $this->callOverHttp('ReadFileMetadata', ['uid' => 10])] as $transport => $text) {
            self::assertStringContainsString('"alternative":"Draft metadata"', $text, $transport . ' reads the draft metadata');
        }
    }

    /**
     * A backend request defers image processing to a later backend request;
     * tools read thumbnails in the same call, so processing stays immediate
     * while the backend view is active, and both are undone afterwards.
     */
    #[Test]
    public function theBackendViewKeepsImageProcessingImmediateAndIsUndone(): void
    {
        $context = $this->getService(Context::class);
        $endpointRequest = $this->createRequest('/mcp', 'POST');
        $GLOBALS['TYPO3_REQUEST'] = $endpointRequest;

        $scope = $this->getService(SiteRequestContext::class)->enterBackendView();
        $backendView = $GLOBALS['TYPO3_REQUEST'] ?? null;
        self::assertInstanceOf(ServerRequestInterface::class, $backendView);
        self::assertTrue(ApplicationType::fromRequest($backendView)->isBackend());
        self::assertFalse($context->getPropertyFromAspect('fileProcessing', 'deferProcessing'));

        $scope->leave();
        self::assertSame($endpointRequest, $GLOBALS['TYPO3_REQUEST'] ?? null);
        self::assertTrue($context->getPropertyFromAspect('fileProcessing', 'deferProcessing'));

        unset($GLOBALS['TYPO3_REQUEST']);
        self::assertFalse($this->getService(SiteRequestContext::class)->enterBackendView()->isPublished(), 'No request, nothing to show differently');
    }

    #[Test]
    public function theDraftFileReferenceIsReadOnEveryTransport(): void
    {
        $arguments = ['table' => self::ITEM_TABLE, 'uid' => 100, 'workspace_id' => $this->workspaceId];

        foreach (['stdio' => $this->callOverStdio('ReadTable', $arguments), 'http' => $this->callOverHttp('ReadTable', $arguments)] as $transport => $text) {
            self::assertStringContainsString('Draft only', $text, $transport . ' reads the draft of the nested file reference');
            self::assertStringNotContainsString('Live alternative', $text, $transport . ' must not mix in the live reference');
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callOverStdio(string $tool, array $arguments): string
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->flushRuntimeCache();
        $handlers = $this->getService(McpServerFactory::class)->createServer()->getHandlers();
        $result = $handlers['tools/call'](new CallToolRequestParams($tool, $arguments));
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR));
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS);

        return $content->text;
    }

    /**
     * Through the middleware, the way an MCP client over HTTP reaches it.
     *
     * @param array<string, mixed> $arguments
     */
    private function callOverHttp(string $tool, array $arguments): string
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $this->flushRuntimeCache();
        $request = $this->createRequest('/mcp', 'POST');
        $token = $this->getService(OAuthService::class)->createDirectAccessToken(1, 'read-parity-test', $request);

        $body = new Stream('php://temp', 'rw');
        $body->write(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
                '_meta' => [
                    MetaKeys::PROTOCOL_VERSION => '2026-07-28',
                    MetaKeys::CLIENT_INFO => ['name' => 'read-parity-test', 'version' => '1.0'],
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
            ->withHeader('Mcp-Name', $tool)
            ->withBody($body);

        $response = GeneralUtility::makeInstance(McpServerMiddleware::class)->process($request, $this->sentinelHandler());
        $endpointRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        self::assertInstanceOf(ServerRequestInterface::class, $endpointRequest);
        self::assertTrue(ApplicationType::fromRequest($endpointRequest)->isFrontend(), 'The endpoint gets its own request back after the tool');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $result = $payload['result'] ?? null;
        self::assertIsArray($result, (string)$response->getBody());
        self::assertFalse($result['isError'] ?? true, (string)$response->getBody());
        $text = $result['content'][0]['text'] ?? null;
        self::assertIsString($text);

        return $text;
    }

    /**
     * Each transport is a request of its own: File and FileReference objects
     * the other one cached (with their metadata) must not answer for it.
     */
    private function flushRuntimeCache(): void
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
    }

    private function createRequest(string $requestUri, string $method): ServerRequestInterface
    {
        $serverParams = [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_METHOD' => $method,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'REQUEST_URI' => $requestUri,
        ];

        // The frontend application sets applicationType before any middleware runs.
        return new ServerRequest(new Uri('https://example.com' . $requestUri), $method, 'php://input', [], $serverParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
    }

    private function sentinelHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response()->withStatus(418);
            }
        };
    }
}
