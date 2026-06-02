<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class McpConnectionDiagnosticServiceTest extends TestCase
{
    #[Test]
    public function runChecksReportsErrorWhenMcpEndpointIsUnreachable(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('connection refused'));

        $service = new McpConnectionDiagnosticService(
            $requestFactory,
            $this->createMock(ExtensionConfiguration::class),
            $this->createLocalModeService(),
            new SiteBaseUrlResolver(),
        );

        $result = $service->runChecks(
            'https://example.com',
            true,
            5,
            0,
            false,
            [
                'command' => 'php',
                'args' => ['/var/www/vendor/bin/typo3', 'mcp:server'],
                'cwd' => '/var/www',
            ],
        );

        self::assertSame('error', $result['overallStatus']);
        $ids = array_column($result['checks'], 'id');
        self::assertContains('mcp_endpoint', $ids);
    }

    #[Test]
    public function runChecksReportsOkWhenMcpEndpointReturns401(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(function (string $url): ResponseInterface {
            $status = str_contains($url, '/mcp') ? 401 : 200;
            $body = str_contains($url, '.well-known') ? '{"resource":"/mcp"}' : '{"error":"Unauthorized"}';

            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($status);
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('__toString')->willReturn($body);
            $response->method('getBody')->willReturn($stream);

            return $response;
        });

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['enableMcpAuthHeaderDiagnostic' => '0']);

        $service = new McpConnectionDiagnosticService(
            $requestFactory,
            $extensionConfiguration,
            $this->createLocalModeService(),
            new SiteBaseUrlResolver(),
        );

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] = 'https://example.com';

        $result = $service->runChecks(
            'https://example.com',
            true,
            3,
            1,
            false,
            [
                'command' => 'php',
                'args' => [PHP_BINARY, 'vendor/bin/typo3', 'mcp:server'],
            ],
        );

        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl']);

        self::assertContains($result['overallStatus'], ['ok', 'warning', 'info']);
        $mcpCheck = $this->findCheck($result['checks'], 'mcp_endpoint');
        self::assertSame('ok', $mcpCheck['status']);
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    private function findCheck(array $checks, string $id): array
    {
        foreach ($checks as $check) {
            if (($check['id'] ?? '') === $id) {
                return $check;
            }
        }

        self::fail('Check not found: ' . $id);
    }

    private function createLocalModeService(): LocalModeService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'localUnsafeMode' => 'off',
        ]);

        return new LocalModeService($extensionConfiguration);
    }
}
