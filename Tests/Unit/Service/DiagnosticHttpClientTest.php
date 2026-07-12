<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as RecordedRequest;
use GuzzleHttp\Psr7\Response;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DiagnosticHttpClient;
use Hn\McpServer\Service\LocalModeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final class DiagnosticHttpClientTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }
        parent::tearDown();
    }

    #[Test]
    public function manifestApprovedSameOriginDiagnosticIsExecuted(): void
    {
        $history = new \ArrayObject();
        $client = $this->createGuzzleClient(
            [new Response(401, [], '{"error":"Unauthorized"}')],
            $history,
        );
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->expects($this->once())->method('getClient')->willReturn($client);

        $subject = $this->createSubject($factory);
        $result = $subject->request('GET', 'https://example.com/mcp');

        self::assertSame(['status' => 401, 'body' => '{"error":"Unauthorized"}'], $result);
        self::assertCount(1, $history);
        $request = $history[0]['request'] ?? null;
        if (!$request instanceof RecordedRequest) {
            self::fail('Expected the diagnostic request in Guzzle history.');
        }
        self::assertSame('/mcp', $request->getRequestTarget());
        self::assertSame('example.com', $request->getHeaderLine('Host'));
    }

    #[Test]
    public function craftedPrivateHostIsRejectedByManifestBeforeClientCreation(): void
    {
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->expects($this->never())->method('getClient');

        $subject = $this->createSubject($factory);
        $result = $subject->request('GET', 'https://127.0.0.1/latest/meta-data');

        self::assertNull($result);
    }

    #[Test]
    public function redirectsAreReturnedWithoutFollowingTheUnvalidatedLocation(): void
    {
        $history = new \ArrayObject();
        $client = $this->createGuzzleClient(
            [
                new Response(302, ['Location' => 'https://127.0.0.1/latest/meta-data']),
                new Response(200, [], 'must not be reached'),
            ],
            $history,
        );
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->expects($this->once())->method('getClient')->willReturn($client);

        $subject = $this->createSubject($factory);
        $result = $subject->request('GET', 'https://example.com/mcp');

        self::assertSame(302, $result['status'] ?? null);
        self::assertCount(1, $history, 'The redirect target must never be requested.');
        $request = $history[0]['request'] ?? null;
        if (!$request instanceof RecordedRequest) {
            self::fail('Expected the diagnostic request in Guzzle history.');
        }
        self::assertSame('example.com', $request->getHeaderLine('Host'));
    }

    /**
     * @param list<Response> $responses
     * @param \ArrayObject<int, array<mixed>> $history
     */
    private function createGuzzleClient(array $responses, \ArrayObject $history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    }

    private function createSubject(GuzzleClientFactory $clientFactory): DiagnosticHttpClient
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'enforceCapabilityManifest' => '1',
            'localUnsafeMode' => 'off',
        ]);
        $localMode = new LocalModeService($extensionConfiguration);

        $manifestPath = tempnam(sys_get_temp_dir(), 'mcp-diagnostic-capabilities-');
        if ($manifestPath === false) {
            self::fail('Could not create a temporary capability manifest.');
        }
        $this->temporaryFiles[] = $manifestPath;
        file_put_contents($manifestPath, json_encode([
            'capabilities' => [
                'network' => [
                    'outbound' => [
                        [
                            'host' => 'example.com',
                            'protocol' => 'https',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $capabilityManifest = new CapabilityManifestService(
            $extensionConfiguration,
            self::createStub(SiteFinder::class),
            $localMode,
            $manifestPath,
        );

        return new DiagnosticHttpClient($clientFactory, $localMode, $capabilityManifest);
    }
}
