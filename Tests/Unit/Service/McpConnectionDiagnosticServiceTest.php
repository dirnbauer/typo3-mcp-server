<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\DiagnosticHttpClient;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class McpConnectionDiagnosticServiceTest extends TestCase
{
    #[Test]
    public function runChecksReportsErrorWhenMcpEndpointIsUnreachable(): void
    {
        $httpClient = $this->createMock(DiagnosticHttpClient::class);
        $httpClient->method('requestMany')->willReturn([
            'mcp_endpoint' => null,
            'oauth_authorization' => null,
            'oauth_protected_resource' => null,
        ]);

        $service = new McpConnectionDiagnosticService(
            $this->createMock(ExtensionConfiguration::class),
            new SiteBaseUrlResolver(),
            $httpClient,
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
        $mcpCheck = $this->findCheck($result['checks'], 'mcp_endpoint');
        self::assertSame('diagnostic.http.unreachable', $mcpCheck['messageKey']);
    }

    #[Test]
    public function runChecksReportsOkWhenMcpEndpointReturns401(): void
    {
        $httpClient = $this->createMock(DiagnosticHttpClient::class);
        $httpClient->method('requestMany')->willReturnCallback(function (array $requests): array {
            $results = [];
            foreach ($requests as $id => $spec) {
                $url = $spec['url'];
                if (str_contains($url, '/mcp')) {
                    $results[$id] = ['status' => 401, 'body' => '{"error":"Unauthorized"}'];
                    continue;
                }
                $results[$id] = ['status' => 200, 'body' => '{"resource":"/mcp"}'];
            }

            return $results;
        });

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['enableMcpAuthHeaderDiagnostic' => '0']);

        $service = new McpConnectionDiagnosticService(
            $extensionConfiguration,
            new SiteBaseUrlResolver(),
            $httpClient,
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
        $oauthCheck = $this->findCheck($result['checks'], 'oauth_authorization');
        self::assertSame('diagnostic.oauthMetadata.ok', $oauthCheck['messageKey']);
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

}
