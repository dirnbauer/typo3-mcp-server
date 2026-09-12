<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Hn\McpServer\MCP\Tool\File\UploadFileFromUrlTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * SSRF and URL validation for UploadFileFromUrl (no outbound HTTP required).
 */
final class UploadFileFromUrlToolTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // The default capability manifest only allows `self` outbound, which
        // would short-circuit the SSRF assertions below by rejecting unknown
        // hosts before they reach UploadFileFromUrl's URL validation. Drop the
        // manifest gate for these tests so the IP-range SSRF check is what
        // rejects private/loopback addresses (which is what we're verifying).
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['enforceCapabilityManifest'] = '0';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['mcp_test_oembed']);
        parent::tearDown();
    }

    #[Test]
    public function rejectsEmptyUrl(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute(['url' => '   ']);

        $this->assertToolError($result, 'url');
    }

    #[Test]
    public function rejectsFileSchemeUrls(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'file:///etc/passwd',
            'path' => 'evil.txt',
        ]);

        $this->assertToolError($result, 'Invalid URL format');
    }

    #[Test]
    public function rejectsPrivateIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://192.168.0.1/readme.txt',
        ]);

        $this->assertToolError($result, 'private or reserved');
    }

    #[Test]
    public function rejectsLoopbackIpv4Literal(): void
    {
        $tool = $this->getService(UploadFileFromUrlTool::class);
        $result = $tool->execute([
            'url' => 'http://127.0.0.1/',
        ]);

        $this->assertToolError($result, 'private or reserved');
    }

    #[Test]
    public function redirectResponseIsNotFollowed(): void
    {
        $body = new Stream('php://temp', 'rw');
        $body->write('redirect');
        $body->rewind();
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects($this->once())
            ->method('request')
            ->with(
                'https://93.184.216.34/file.txt',
                'GET',
                self::callback(static fn(array $options): bool => ($options['allow_redirects'] ?? null) === false),
            )
            ->willReturn(new Response($body, 302, ['Location' => 'http://127.0.0.1/private']));

        $tool = $this->createToolWithRequestFactory($requestFactory);
        $result = $tool->execute(['url' => 'https://93.184.216.34/file.txt']);

        $this->assertToolError($result, 'HTTP 302');
    }

    #[Test]
    public function rejectsWebPagesWithAnActionableMessage(): void
    {
        $tool = $this->createToolWithRequestFactory($this->createRequestFactoryReturning(
            "<!DOCTYPE html>\n<html><head><title>Not a file</title></head><body>page</body></html>",
            'text/html; charset=utf-8',
        ));

        $result = $tool->execute(['url' => 'https://93.184.216.34/gallery/photo']);

        $this->assertToolError($result, 'web page');
        self::assertSame(0, (int)$this->getConnectionForTable('sys_file')->count('uid', 'sys_file', []));
    }

    #[Test]
    public function webPageCannotBeSmuggledInUnderAnImageFileName(): void
    {
        $tool = $this->createToolWithRequestFactory($this->createRequestFactoryReturning(
            '<html><body><img src="x"></body></html>',
            'image/jpeg',
        ));

        $result = $tool->execute(['url' => 'https://93.184.216.34/photo.jpg', 'path' => 'images/photo.jpg']);

        $this->assertToolError($result, 'web page');
    }

    #[Test]
    public function refusesExecutableFileNamesDerivedFromTheUrl(): void
    {
        $tool = $this->createToolWithRequestFactory($this->createRequestFactoryReturning('<?php echo 1;', 'text/plain'));

        $result = $tool->execute(['url' => 'https://93.184.216.34/shell.php']);

        $this->assertToolError($result, 'not allowed');
        self::assertSame(0, (int)$this->getConnectionForTable('sys_file')->count('uid', 'sys_file', []));
    }

    #[Test]
    public function prefersContentDispositionFileNameOverUrlPath(): void
    {
        $tool = $this->createToolWithRequestFactory($this->createRequestFactoryReturning(
            "id,name\n1,Example\n",
            'text/csv',
            ['Content-Disposition' => 'attachment; filename="price-list.csv"'],
        ));

        $result = $tool->execute(['url' => 'https://93.184.216.34/download?id=42']);

        $this->assertSuccessfulToolResult($result);
        $json = $this->extractJsonFromResult($result);
        self::assertSame('price-list.csv', $json['originalFilename']);
        self::assertStringEndsWith('.csv', (string)$json['storedFilename']);
    }

    #[Test]
    public function downloadedFilesAreDeduplicated(): void
    {
        $csv = "id,name\n1,Example\n";
        $first = $this->extractJsonFromResult(
            $this->createToolWithRequestFactory($this->createRequestFactoryReturning($csv, 'text/csv'))
                ->execute(['url' => 'https://93.184.216.34/a.csv']),
        );
        $secondResult = $this->createToolWithRequestFactory($this->createRequestFactoryReturning($csv, 'text/csv'))
            ->execute(['url' => 'https://93.184.216.34/b.csv', 'path' => 'other/b.csv']);

        $this->assertSuccessfulToolResult($secondResult);
        $second = $this->extractJsonFromResult($secondResult);
        self::assertTrue($second['deduplicated']);
        self::assertSame($first['uid'], $second['uid']);
    }

    #[Test]
    public function youtubeUrlBecomesAnOnlineMediaAssetWithoutDownloading(): void
    {
        $this->mockOEmbedLookups('Company Film');
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects($this->never())->method('request');
        $tool = $this->createToolWithRequestFactory($requestFactory);

        $result = $tool->execute(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'path' => 'videos/']);

        $this->assertSuccessfulToolResult($result);
        $json = $this->extractJsonFromResult($result);
        self::assertSame('online_media_created', $json['action']);
        self::assertTrue($json['onlineMedia']);
        self::assertFalse($json['deduplicated']);
        self::assertStringEndsWith('.youtube', (string)$json['identifier']);
        self::assertStringContainsString('/videos/', (string)$json['identifier']);

        $row = $this->getConnectionForTable('sys_file')->select(['extension'], 'sys_file', ['uid' => (int)$json['uid']])->fetchAssociative();
        self::assertSame('youtube', $row['extension'] ?? null);

        // The same video again is recognized as already existing.
        $again = $this->extractJsonFromResult($tool->execute(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'path' => 'videos/']));
        self::assertTrue($again['deduplicated']);
        self::assertSame($json['uid'], $again['uid']);
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function createRequestFactoryReturning(string $body, string $contentType, array $extraHeaders = []): RequestFactory
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')
            ->willReturn(new Response($stream, 200, ['Content-Type' => $contentType] + $extraHeaders));

        return $requestFactory;
    }

    /**
     * Intercept the YouTube oEmbed title lookup made by the core online media
     * helper so the test never touches the network.
     */
    private function mockOEmbedLookups(string $title): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['mcp_test_oembed'] = (static fn(callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $title) {
            if (str_contains((string)$request->getUri(), 'oembed')) {
                return new FulfilledPromise(new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string)json_encode(['title' => $title])));
            }

            return $handler($request, $options);
        });
    }

    private function createToolWithRequestFactory(RequestFactory $requestFactory): UploadFileFromUrlTool
    {
        $registeredTool = $this->getService(UploadFileFromUrlTool::class);
        self::assertInstanceOf(UploadFileFromUrlTool::class, $registeredTool);

        return new UploadFileFromUrlTool(
            $this->readToolDependency($registeredTool, 'fileSandboxService'),
            $requestFactory,
            $this->readToolDependency($registeredTool, 'capabilityManifest'),
            $this->readToolDependency($registeredTool, 'localMode'),
            $this->readToolDependency($registeredTool, 'fileMetadataIndexService'),
            $this->readToolDependency($registeredTool, 'outboundUrlGuard'),
            $this->readToolDependency($registeredTool, 'fileUploadService'),
            $this->readToolDependency($registeredTool, 'onlineMediaHelperRegistry'),
        );
    }

    /**
     * @template T of object
     * @return T
     */
    private function readToolDependency(UploadFileFromUrlTool $tool, string $property): object
    {
        $reflection = new \ReflectionProperty($tool, $property);
        $value = $reflection->getValue($tool);
        self::assertIsObject($value);
        /** @var T $value */
        return $value;
    }
}
