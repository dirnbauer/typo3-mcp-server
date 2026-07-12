<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\OutboundUrlGuardService;
use Hn\McpServer\Service\X402\X402FacilitatorVerificationService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

final class X402FacilitatorVerificationServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    public function testManifestDenialPreventsRequest(): void
    {
        $requestCalled = false;
        $service = $this->createSubject(
            'allowed.example',
            static function () use (&$requestCalled): ResponseInterface {
                $requestCalled = true;
                return new Response(200, [], '{"valid":true}');
            },
        );

        self::assertFalse($service->verifyAndSettle(
            'https://facilitator.example',
            $this->paymentProof(),
            $this->paymentRequirement(),
        ));
        self::assertFalse($requestCalled);
    }

    public function testRedirectIsRejectedAndFollowingIsDisabled(): void
    {
        $service = $this->createSubject(
            'facilitator.example',
            static function (string $url, string $method, array $options): ResponseInterface {
                self::assertSame('https://facilitator.example/verify', $url);
                self::assertSame('POST', $method);
                self::assertFalse($options['allow_redirects']);
                return new Response(302, ['Location' => 'http://127.0.0.1/private']);
            },
        );

        self::assertFalse($service->verifyAndSettle(
            'https://facilitator.example',
            $this->paymentProof(),
            $this->paymentRequirement(),
        ));
    }

    public function testOversizeResponseIsRejectedEvenWithoutContentLength(): void
    {
        $service = $this->createSubject(
            'facilitator.example',
            static fn(): ResponseInterface => new Response(200, [], str_repeat('x', (64 * 1024) + 1)),
        );

        self::assertFalse($service->verifyAndSettle(
            'https://facilitator.example',
            $this->paymentProof(),
            $this->paymentRequirement(),
        ));
    }

    public function testValidResponseUsesPinnedAddressAndBoundedStreaming(): void
    {
        $service = $this->createSubject(
            'facilitator.example',
            static function (string $url, string $method, array $options): ResponseInterface {
                self::assertContains($url, [
                    'https://facilitator.example/verify',
                    'https://facilitator.example/settle',
                ]);
                $stream = $options['stream'] ?? null;
                self::assertIsBool($stream);
                self::assertTrue($stream);
                $httpErrors = $options['http_errors'] ?? null;
                self::assertIsBool($httpErrors);
                self::assertFalse($httpErrors);
                $curlOptions = $options['curl'] ?? null;
                self::assertIsArray($curlOptions);
                self::assertSame(
                    ['facilitator.example:443:8.8.8.8'],
                    $curlOptions[CURLOPT_RESOLVE] ?? null,
                );
                $json = $options['json'] ?? null;
                self::assertIsArray($json);
                self::assertSame(2, $json['x402Version'] ?? null);
                self::assertIsArray($json['paymentPayload'] ?? null);
                self::assertSame('exact', $json['paymentPayload']['scheme'] ?? null);
                self::assertIsArray($json['paymentRequirements'] ?? null);
                return str_ends_with($url, '/verify')
                    ? new Response(200, ['Content-Type' => 'application/json'], '{"isValid":true,"payer":"0x1"}')
                    : new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"transaction":"0xabc","network":"eip155:84532","payer":"0x1"}');
            },
        );

        self::assertTrue($service->verifyAndSettle(
            'https://facilitator.example',
            $this->paymentProof(),
            $this->paymentRequirement(),
        ));
    }

    private function paymentProof(): string
    {
        return base64_encode((string)json_encode([
            'x402Version' => 2,
            'scheme' => 'exact',
            'network' => 'eip155:84532',
            'payload' => ['signature' => '0xproof'],
            'accepted' => $this->paymentRequirement(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function paymentRequirement(): array
    {
        return [
            'scheme' => 'exact',
            'network' => 'eip155:84532',
            'amount' => '10000',
            'asset' => '0xasset',
            'payTo' => '0xmerchant',
        ];
    }

    /**
     * @param \Closure(string, string, array<string, mixed>): ResponseInterface $request
     */
    private function createSubject(string $allowedHost, \Closure $request): X402FacilitatorVerificationService
    {
        $manifestPath = tempnam(sys_get_temp_dir(), 'mcp-x402-cap-');
        if ($manifestPath === false) {
            self::fail('Could not create a temporary capability manifest.');
        }
        $this->temporaryFiles[] = $manifestPath;
        file_put_contents($manifestPath, Yaml::dump([
            'capabilities' => [
                'network' => [
                    'outbound' => [[
                        'host' => $allowedHost,
                        'protocol' => 'https',
                    ]],
                ],
            ],
        ]));

        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $configuration = is_array($configuration) ? $configuration : [];
        $extensions = $configuration['EXTENSIONS'] ?? [];
        $extensions = is_array($extensions) ? $extensions : [];
        $extensions['mcp_server'] = [
            'enforceCapabilityManifest' => '1',
            'localUnsafeMode' => 'off',
        ];
        $configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $configuration;
        $extensionConfiguration = new ExtensionConfiguration();
        $localMode = new LocalModeService($extensionConfiguration);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $capabilityManifest = new CapabilityManifestService(
            $extensionConfiguration,
            $siteFinder,
            $localMode,
            $manifestPath,
        );

        return new X402FacilitatorVerificationService(
            new RequestFactory(new GuzzleClientFactory()),
            $capabilityManifest,
            $localMode,
            new OutboundUrlGuardService(static fn(string $host): array => ['8.8.8.8']),
            $request,
        );
    }
}
