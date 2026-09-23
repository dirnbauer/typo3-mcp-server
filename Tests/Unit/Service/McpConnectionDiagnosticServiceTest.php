<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\DiagnosticHttpClient;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class McpConnectionDiagnosticServiceTest extends TestCase
{
    #[Test]
    public function runChecksReportsErrorWhenMcpEndpointIsUnreachable(): void
    {
        $httpClient = self::createStub(DiagnosticHttpClient::class);
        $httpClient->method('requestMany')->willReturn([
            'mcp_endpoint' => null,
            'oauth_authorization' => null,
            'oauth_protected_resource' => null,
        ]);

        $service = new McpConnectionDiagnosticService(
            self::createStub(ExtensionConfiguration::class),
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
        $httpClient = self::createStub(DiagnosticHttpClient::class);
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

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
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

    #[Test]
    public function aDisabledAuthHeaderCheckPointsAtItsOwnFixHint(): void
    {
        $httpClient = self::createStub(DiagnosticHttpClient::class);
        $httpClient->method('requestMany')->willReturn([]);
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['enableMcpAuthHeaderDiagnostic' => '0']);

        $result = new McpConnectionDiagnosticService($extensionConfiguration, new SiteBaseUrlResolver(), $httpClient)
            ->runChecks('https://example.com', true, 3, 0, false, ['command' => 'php', 'args' => ['vendor/bin/typo3', 'mcp:server']]);

        $authCheck = $this->findCheck($result['checks'], 'auth_header');
        self::assertSame('diagnostic.authHeader.disabled', $authCheck['messageKey']);
        self::assertSame('diagnostic.authHeader.fixDisabled', $authCheck['fixHintKey']);
    }

    /**
     * Every key any check can produce has an English source and a German
     * target, whatever the probes answer.
     *
     * @param array<string, array{status: int, body: string}|null> $responses
     */
    #[Test]
    #[DataProvider('probeOutcomes')]
    public function everyEmittedLabelExistsInBothLanguages(array $responses, bool $authDiagnostic, bool $localhost): void
    {
        $httpClient = self::createStub(DiagnosticHttpClient::class);
        $httpClient->method('requestMany')->willReturnCallback(
            static fn(array $requests): array => array_intersect_key($responses + array_fill_keys(array_keys($requests), null), $requests),
        );
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['enableMcpAuthHeaderDiagnostic' => $authDiagnostic ? '1' : '0']);

        $checks = new McpConnectionDiagnosticService($extensionConfiguration, new SiteBaseUrlResolver(), $httpClient)
            ->runChecks('https://example.com', false, 0, 0, $localhost, ['command' => 'php', 'args' => ['/nonexistent/vendor/bin/typo3', 'mcp:server']])['checks'];

        $english = self::unitIds('locallang_mod.xlf');
        $german = self::unitIds('de.locallang_mod.xlf');
        foreach ($checks as $check) {
            foreach (['labelKey', 'messageKey', 'howToCheckKey', 'fixHintKey'] as $field) {
                self::assertContains($check[$field], $english, $check['id'] . ' ' . $field);
                self::assertContains($check[$field], $german, $check['id'] . ' ' . $field);
            }
        }
    }

    /**
     * @return iterable<string, array{0: array<string, array{status: int, body: string}|null>, 1: bool, 2: bool}>
     */
    public static function probeOutcomes(): iterable
    {
        yield 'nothing answers' => [[], true, true];
        yield 'everything answers' => [[
            'mcp_endpoint' => ['status' => 401, 'body' => '{}'],
            'oauth_authorization' => ['status' => 200, 'body' => '{"resource":"/mcp"}'],
            'oauth_protected_resource' => ['status' => 200, 'body' => '{"resource":"/mcp"}'],
            'auth_header' => ['status' => 200, 'body' => '{"headers_received":{"authorization":true}}'],
        ], true, false];
        yield 'unexpected answers' => [[
            'mcp_endpoint' => ['status' => 500, 'body' => ''],
            'oauth_authorization' => ['status' => 200, 'body' => '{}'],
            'oauth_protected_resource' => ['status' => 404, 'body' => ''],
            'auth_header' => ['status' => 200, 'body' => '{}'],
        ], true, false];
        yield 'auth diagnostic off' => [[], false, false];
    }

    /**
     * @return list<string>
     */
    private static function unitIds(string $file): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/Resources/Private/Language/' . $file);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');

        return array_values(array_map(static fn(\SimpleXMLElement $unit): string => (string)$unit['id'], $xml->xpath('//x:unit') ?: []));
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
