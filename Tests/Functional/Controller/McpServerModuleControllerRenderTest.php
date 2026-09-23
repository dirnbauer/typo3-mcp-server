<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Controller;

use Hn\McpServer\Controller\McpServerModuleController;
use Hn\McpServer\Http\AjaxRequestBodyParser;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DiagnosticHttpClient;
use Hn\McpServer\Service\LocalModeService;
use Hn\McpServer\Service\McpClientConfigBuilder;
use Hn\McpServer\Service\McpConnectionDiagnosticService;
use Hn\McpServer\Service\McpModulePartialRenderer;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteBaseUrlResolver;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The module renders: the guard against a Fluid template or label that only
 * breaks when somebody opens the module. The connection check runs against
 * an HTTP client that answers nothing, so no request leaves the test.
 */
final class McpServerModuleControllerRenderTest extends AbstractFunctionalTest
{
    #[Test]
    public function rendersTheFourSectionsWithAnEmptyTokenList(): void
    {
        $html = (string)$this->controller()->mainAction($this->request())->getBody();

        self::assertStringContainsString('<h1>MCP Server</h1>', $html);
        foreach (['setup', 'tokens', 'check', 'tools'] as $pane) {
            self::assertStringContainsString('id="mcp-tab-' . $pane . '"', $html, $pane);
            self::assertStringContainsString('data-typo3-tab="#mcp-tab-' . $pane . '"', $html, $pane);
        }

        // DocHeader and empty state both offer the primary action.
        self::assertGreaterThanOrEqual(2, substr_count($html, 'data-mcp-action="create-token"'));
        self::assertStringContainsString('No access tokens yet', $html);

        self::assertStringContainsString('value="https://localhost/mcp"', $html);
        self::assertStringContainsString('<typo3-copy-to-clipboard text="https://localhost/mcp"', $html);
        foreach (['claude', 'cursor', 'codex'] as $client) {
            self::assertStringContainsString('id="mcp-client-' . $client . '"', $html, $client);
        }

        // Nothing answers the diagnostic probes: the check reports errors and
        // the setup pane points there.
        self::assertStringContainsString('MCP is not ready yet', $html);
        self::assertStringContainsString('data-check-status="error"', $html);

        $toolCount = count($this->get(ToolRegistry::class)->getTools());
        self::assertGreaterThan(0, $toolCount);
        self::assertSame($toolCount, substr_count($html, '<tr data-mcp-tool="'));
        self::assertStringContainsString('<code>ReadTable</code>', $html);

        self::assertStringNotContainsString('LLL:', $html);
        self::assertDoesNotMatchRegularExpression('/>\s*(?:tab|tokens|setup|diagnostic|tools|claude|cursor|codex|js)\.[A-Za-z.]+\s*</', $html, 'every label is resolved');
    }

    #[Test]
    public function listsTokensAndRefreshesThemThroughTheSamePartial(): void
    {
        $this->get(OAuthService::class)->createDirectAccessToken(1, 'Claude <Desktop>');

        $html = (string)$this->controller()->mainAction($this->request())->getBody();
        self::assertStringContainsString('<th scope="row">Claude &lt;Desktop&gt;</th>', $html);
        self::assertStringContainsString('data-mcp-action="revoke-token"', $html);
        self::assertStringContainsString('1 active token', $html);
        self::assertStringContainsString('data-mcp-action="revoke-all-tokens"', $html);

        $response = $this->controller()->getUserTokensAction($this->request());
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertSame(1, $payload['count']);
        self::assertIsString($payload['html']);
        self::assertStringContainsString('<th scope="row">Claude &lt;Desktop&gt;</th>', $payload['html']);
    }

    #[Test]
    public function rendersInGerman(): void
    {
        // Fluid resolves labels for the backend user's language, PHP through
        // $GLOBALS['LANG']; a German backend user sets both.
        $backendUser = $this->setupDefaultBackendUser();
        $backendUser->user['lang'] = 'de';
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $html = (string)$this->controller()->mainAction($this->request())->getBody();

        self::assertStringContainsString('<h1>MCP-Server</h1>', $html);
        self::assertStringContainsString('Client verbinden', $html);
        self::assertStringContainsString('Noch keine Zugriffstoken', $html);
        self::assertStringContainsString('Verbindungsprüfung', $html);
    }

    private function controller(): McpServerModuleController
    {
        $unreachable = new readonly class (
            $this->get(GuzzleClientFactory::class),
            $this->get(LocalModeService::class),
            $this->get(CapabilityManifestService::class),
        ) extends DiagnosticHttpClient {
            public function requestMany(array $requests): array
            {
                return array_fill_keys(array_keys($requests), null);
            }

            public function request(string $method, string $url, array $headers = []): ?array
            {
                return null;
            }
        };
        $baseUrlResolver = $this->get(SiteBaseUrlResolver::class);

        return new McpServerModuleController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(ComponentFactory::class),
            $this->get(IconFactory::class),
            $this->get(ToolRegistry::class),
            $this->get(OAuthService::class),
            $this->get(UriBuilder::class),
            $this->get(ConnectionPool::class),
            $this->get(FormProtectionFactory::class),
            new McpConnectionDiagnosticService($this->get(ExtensionConfiguration::class), $baseUrlResolver, $unreachable),
            $baseUrlResolver,
            $this->get(AjaxRequestBodyParser::class),
            $this->get(McpClientConfigBuilder::class),
            $this->get(McpModulePartialRenderer::class),
        );
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('https://localhost/typo3/module/user/mcp-server')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            // packageName is how BackendViewFactory finds this extension's
            // templates; the real backend route carries it.
            ->withAttribute('route', new Route('/module/user/mcp-server', ['packageName' => 'hn/typo3-mcp-server']))
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'localhost',
                'HTTPS' => 'on',
                'SCRIPT_NAME' => '/index.php',
            ]));
    }
}
