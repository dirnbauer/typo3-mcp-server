<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Integration\Abilities;

use Hn\McpServer\Http\AuthenticationRateLimiter;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Integration\Abilities\AbilityTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\MetaKeys;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The abilities registry projected into the booted MCP catalog: the bridge
 * has to survive real container compilation (the catalog abilities depend on
 * the very ToolRegistry that lists them) and a real MCP tools/call.
 */
final class AbilityToolBridgeTest extends AbstractFunctionalTest
{
    private const PROTOCOL_VERSION = '2026-07-28';

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'webconsulting/typo3-abilities',
    ];

    private mixed $previousRequest = null;

    private string $accessToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    #[Test]
    public function bootedRegistryListsEveryMcpExposedAbilityAsATool(): void
    {
        $names = array_keys($this->getService(ToolRegistry::class)->getTools());

        // Shipped by webconsulting/typo3-abilities itself …
        self::assertContains('ability_system_site-info', $names);
        self::assertContains('ability_abilities_list', $names);
        self::assertContains('ability_abilities_describe', $names);
        // … and the fork's own catalog abilities.
        self::assertContains('ability_typo3-mcp_list-tools', $names);
        self::assertContains('ability_typo3-mcp_describe-tool', $names);
        self::assertContains('ability_typo3-mcp_list-skills', $names);
        self::assertContains('ability_typo3-mcp_get-skill', $names);

        // Generic tool execution stays off the MCP surface: projecting it into
        // the catalog it executes would duplicate every native tool.
        self::assertNotContains('ability_typo3-mcp_execute-tool', $names);

        // Native tools are unaffected by the bridge.
        self::assertContains('ReadTable', $names);
        self::assertContains('GetCapabilities', $names);
    }

    #[Test]
    public function bridgedToolsCarryTheirRegistrySchemaAndAnnotations(): void
    {
        $tool = $this->getService(ToolRegistry::class)->getTool('ability_system_site-info');

        self::assertInstanceOf(AbilityTool::class, $tool);
        self::assertSame('system/site-info', $tool->getDefinition()->name);

        $schema = $tool->getSchema();
        self::assertStringContainsString('Site info', (string)$schema['description']);
        self::assertSame('object', $schema['inputSchema']['type'] ?? null);
        self::assertTrue($schema['annotations']['readOnlyHint'] ?? null);
        self::assertFalse($schema['annotations']['destructiveHint'] ?? null);
    }

    #[Test]
    public function siteInfoAbilityIsCallableThroughTheMcpEndpoint(): void
    {
        $this->createSiteConfiguration('bridge-site', 'https://bridge.example.org/');

        $response = $this->callTool('ability_system_site-info');
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $result = $this->decodeResponse($response)['result'] ?? null;
        self::assertIsArray($result);
        self::assertNotTrue($result['isError'] ?? false, (string)json_encode($result));

        $payload = $result['structuredContent'] ?? null;
        self::assertIsArray($payload, (string)json_encode($result));
        self::assertTrue($payload['ok'] ?? null);
        self::assertIsString($payload['data']['typo3Version'] ?? null);
        self::assertSame(
            ['bridge-site'],
            array_column($payload['data']['sites'] ?? [], 'identifier'),
        );
        self::assertSame('https://bridge.example.org/', $payload['data']['sites'][0]['base'] ?? null);
    }

    #[Test]
    public function registryAbilityListsItselfThroughTheMcpEndpoint(): void
    {
        $response = $this->callTool('ability_abilities_list', ['surface' => 'mcp']);
        $result = $this->decodeResponse($response)['result'] ?? null;

        self::assertIsArray($result);
        self::assertNotTrue($result['isError'] ?? false, (string)json_encode($result));
        $abilities = $result['structuredContent']['data']['abilities'] ?? null;
        self::assertIsArray($abilities);

        $mcpToolNames = array_column($abilities, 'mcpToolName');
        self::assertContains('ability_system_site-info', $mcpToolNames);
        self::assertContains('ability_typo3-mcp_list-tools', $mcpToolNames);
        self::assertNotContains('ability_typo3-mcp_execute-tool', $mcpToolNames);
    }

    #[Test]
    public function unknownAbilityToolIsRejectedLikeAnyOtherUnknownTool(): void
    {
        $response = $this->callTool('ability_nope_nope');

        self::assertSame(-32602, $this->decodeResponse($response)['error']['code'] ?? null);
    }

    /**
     * Calls through the stateless 2026-07-28 era so the assertion covers the
     * real endpoint without an initialize/session dance.
     *
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $name, array $arguments = []): ResponseInterface
    {
        return $this->sendJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments === [] ? new \stdClass() : $arguments,
                '_meta' => [
                    MetaKeys::PROTOCOL_VERSION => self::PROTOCOL_VERSION,
                    MetaKeys::CLIENT_INFO => ['name' => 'functional-ability-bridge', 'version' => '1.0'],
                    MetaKeys::CLIENT_CAPABILITIES => [],
                ],
            ],
        ], $name);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJsonRpc(array $payload, string $toolName): ResponseInterface
    {
        $factory = GeneralUtility::makeInstance(ServerRequestFactory::class);
        $request = $factory->createServerRequest('POST', 'https://example.org/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('MCP-Protocol-Version', self::PROTOCOL_VERSION)
            ->withHeader('Mcp-Method', 'tools/call')
            ->withHeader('Mcp-Name', $toolName);

        if ($this->accessToken === '') {
            $this->accessToken = $this->getService(OAuthService::class)
                ->createDirectAccessToken(1, 'functional-ability-bridge', $request);
        }

        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode($payload, JSON_THROW_ON_ERROR));
        $body->rewind();
        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withBody($body);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return ($this->createEndpoint())($request);
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createEndpoint(): McpEndpoint
    {
        $container = $this->getContainer();
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(McpEndpoint::class);
        assert($logger instanceof LoggerInterface);

        return new McpEndpoint(
            $logger,
            $this->getService(OAuthService::class),
            new BackendUserContextService(
                $this->getService(ConnectionPool::class),
                GeneralUtility::makeInstance(Context::class),
                $this->getService(WorkspaceContextService::class),
                $this->getService(LanguageServiceFactory::class),
            ),
            new ExtensionConfiguration(),
            new SiteBaseUrlResolver(),
            authenticationRateLimiter: new AuthenticationRateLimiter(
                $container->get(RateLimiterFactory::class),
                new NullLogger(),
            ),
        );
    }

    private function createSiteConfiguration(string $identifier, string $base): void
    {
        $this->getService(SiteWriter::class)->write($identifier, [
            'rootPageId' => $this->getRootPageUid(),
            'base' => $base,
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
        ]);
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
    }
}
