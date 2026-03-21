<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * HTTP entry hardening: auth diagnostic toggle and query-token default.
 */
final class McpEndpointSecurityTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    /** @var array<string, mixed> */
    private array $originalMcpExtensionSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMcpExtensionSettings = \is_array($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null)
            ? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']
            : [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->originalMcpExtensionSettings;
        parent::tearDown();
    }

    public function testAuthHeaderDiagnosticDisabledReturns403(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['enableMcpAuthHeaderDiagnostic' => '0'],
        );

        $endpoint = $this->getContainer()->get(McpEndpoint::class);
        self::assertInstanceOf(McpEndpoint::class, $endpoint);

        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth']);

        $response = $endpoint($request);

        self::assertSame(403, $response->getStatusCode());
        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertArrayHasKey('error', $json);
        self::assertSame('forbidden', $json['error']);
    }

    public function testAuthHeaderDiagnosticOmitsServerSoftwareFingerprint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['enableMcpAuthHeaderDiagnostic' => '1'],
        );

        $endpoint = $this->getContainer()->get(McpEndpoint::class);
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('GET', 'https://example.org/mcp')
            ->withQueryParams(['test' => 'auth']);

        $response = $endpoint($request);
        self::assertSame(200, $response->getStatusCode());

        $json = json_decode((string)$response->getBody(), true);
        self::assertIsArray($json);
        self::assertArrayNotHasKey('server_software', $json);
        self::assertArrayHasKey('auth_header_detected', $json);
        self::assertArrayHasKey('headers_received', $json);
    }

    public function testQueryTokenIsIgnoredWhenExtensionSettingDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $this->originalMcpExtensionSettings,
            ['allowMcpTokenInQueryString' => '0'],
        );

        $endpoint = $this->getContainer()->get(McpEndpoint::class);
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withQueryParams(['token' => 'not-a-real-token']);

        $response = $endpoint($request);
        self::assertSame(401, $response->getStatusCode());
    }
}
