<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageToolTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);

        // Create proper site configuration for real URL testing
        $this->createTestSiteConfiguration();
    }

    /**
     * Create a proper site configuration for testing URL generation
     */
    protected function createTestSiteConfiguration(): void
    {
        // Create the sites directory if it doesn't exist
        $sitesDir = $this->instancePath . '/typo3conf/sites';
        if (!is_dir($sitesDir)) {
            GeneralUtility::mkdir_deep($sitesDir);
        }

        // Always use manual creation to ensure it works reliably
        $this->createSiteConfigurationManually();

        // Flush site configuration cache to ensure it's picked up
        $this->flushSiteConfigurationCache();
    }

    /**
     * Fallback method to create site configuration manually
     */
    protected function createSiteConfigurationManually(): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);

        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'hreflang' => 'en-us',
                    'direction' => 'ltr',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        // Write YAML file manually using Symfony YAML component
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        $configFile = $siteDir . '/config.yaml';
        GeneralUtility::writeFile($configFile, $yamlContent, true);
    }

    /**
     * Flush site configuration cache to ensure changes are picked up
     */
    protected function flushSiteConfigurationCache(): void
    {
        // Try to clear the cache files manually as well
        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // Clear global caches if available
        if (class_exists(CacheManager::class)) {
            try {
                $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
                if ($cacheManager->hasCache('core')) {
                    $cacheManager->getCache('core')->remove('sites-configuration');
                }
                if ($cacheManager->hasCache('runtime')) {
                    $cacheManager->getCache('runtime')->remove('sites-configuration');
                }
            } catch (\Throwable) {
                // Ignore cache errors during tests
            }
        }
    }

    /**
     * Test that site configuration is properly created and can be found
     */
    public function testSiteConfigurationCreated(): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        try {
            $site = $siteFinder->getSiteByPageId(1);
            self::assertNotNull($site);
            self::assertEquals('test-site', $site->getIdentifier());
            self::assertEquals(1, $site->getRootPageId());
            self::assertEquals('https://example.com/', $site->getBase()->__toString());
        } catch (\Throwable $e) {
            self::fail('Site configuration not found or invalid: ' . $e->getMessage());
        }
    }

    /**
     * Test URL generation directly using TYPO3 site configuration
     */
    public function testDirectUrlGeneration(): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        try {
            // Test URL generation for page 6 (Contact)
            $site = $siteFinder->getSiteByPageId(6);
            self::assertNotNull($site);

            $language = $site->getLanguageById(0);
            $uri = $site->getRouter()->generateUri(6, ['_language' => $language]);
            $url = (string)$uri;

            self::assertStringContainsString('https://example.com', $url);
            self::assertStringContainsString('/contact', $url);
        } catch (\Throwable $e) {
            self::fail('Direct URL generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Test getting page information directly through the tool
     */
    public function testGetPageDirectly(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test getting page information for Home page
        $result = $tool->execute([
            'uid' => 1,
            'includeHidden' => false,
            'languageId' => 0,
        ]);

        // Verify result structure
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Verify basic page information is present
        self::assertStringContainsString('PAGE INFORMATION', $content);
        self::assertStringContainsString('UID: 1', $content);
        self::assertStringContainsString('Title: Home', $content);
        self::assertStringContainsString('Parent Page (PID): 0', $content);
        self::assertStringContainsString('Hidden: No', $content);

        // Verify content elements are listed
        self::assertStringContainsString('RECORDS ON THIS PAGE', $content);
        self::assertStringContainsString('Content Elements (tt_content)', $content);
        self::assertStringContainsString('[100] Welcome Header', $content);
        self::assertStringContainsString('[101] About Section', $content);

        // Hidden content should now be included (always show hidden records)
        self::assertStringContainsString('[104] Hidden Content', $content);
    }

    /**
     * Test getting page with URL generation
     */
    public function testGetPageWithUrl(): void
    {
        $tool = $this->getService(GetPageTool::class);

        $result = $tool->execute([
            'uid' => 2,
            'languageId' => 0,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify page information
        self::assertStringContainsString('UID: 2', $content);
        self::assertStringContainsString('Title: About', $content);
        self::assertStringContainsString('Navigation Title: About Us', $content);

        // With site configuration, should generate full URLs
        self::assertStringContainsString('URL:', $content);
        // Should show full site URL with site config
        self::assertStringContainsString('https://example.com/about', $content);
    }

    /**
     * Test getting page with content elements showing proper structure
     */
    public function testGetPageWithContentElements(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test page 2 (About) which has content elements
        $result = $tool->execute([
            'uid' => 2,
            'includeHidden' => false,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify content elements are properly listed
        self::assertStringContainsString('Content Elements (tt_content)', $content);
        self::assertStringContainsString('[102] Team Introduction', $content);
        self::assertStringContainsString('[103] Team Members', $content);

        // Verify content types are shown
        self::assertStringContainsString('Type:', $content);
        self::assertStringContainsString('textmedia', $content);

        // Verify column position information
        self::assertStringContainsString('[colPos: 0]', $content);
        // Column name can vary based on backend layout configuration
        self::assertMatchesRegularExpression('/Column: .+ \[colPos: 0\]/', $content);
    }

    public function testGetPageCountsVisibleImageReferences(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');

        $writeTool = $this->getService(WriteTableTool::class);
        $writeResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'bottom',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Image Summary Test',
                'colPos' => 0,
                'assets' => [1],
            ],
        ]);

        self::assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $tool = $this->getService(GetPageTool::class);
        $result = $tool->execute([
            'uid' => 1,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        self::assertStringContainsString('Image Summary Test', $content);
        self::assertStringContainsString('Images: 1', $content);
    }

    /**
     * Test error handling for non-existent page
     */
    public function testGetNonExistentPage(): void
    {
        $tool = $this->getService(GetPageTool::class);

        $result = $tool->execute([
            'uid' => 999,
        ]);

        // Should return an error
        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $errorMessage = $result->content[0]->text;
        self::assertStringContainsString('Operation failed (GetPage)', $errorMessage);
    }

    /**
     * Test invalid page UID (zero or negative)
     */
    public function testInvalidPageUid(): void
    {
        $tool = $this->getService(GetPageTool::class);

        $result = $tool->execute([
            'uid' => 0,
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        self::assertStringContainsString('Invalid page UID', $errorMessage);

        // Test negative UID
        $result = $tool->execute([
            'uid' => -1,
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        self::assertStringContainsString('Invalid page UID', $errorMessage);
    }

    /**
     * Test getting page through ToolRegistry
     */
    public function testGetPageThroughRegistry(): void
    {
        // Create tool registry with the GetPageTool
        $tools = [$this->getService(GetPageTool::class)];
        $registry = new ToolRegistry($tools);

        // Get tool from registry
        $tool = $registry->getTool('GetPage');
        self::assertNotNull($tool);
        self::assertInstanceOf(GetPageTool::class, $tool);

        // Execute through registry
        $result = $tool->execute([
            'uid' => 1,
            'includeHidden' => false,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        self::assertStringContainsString('UID: 1', $content);
        self::assertStringContainsString('Title: Home', $content);
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = $this->getService(GetPageTool::class);
        self::assertEquals('GetPage', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = $this->getService(GetPageTool::class);
        $schema = $tool->getSchema();

        self::assertIsArray($schema);
        self::assertArrayHasKey('description', $schema);
        self::assertArrayHasKey('inputSchema', $schema);
        self::assertArrayHasKey('properties', $schema['inputSchema']);
        self::assertArrayHasKey('uid', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('language', $schema['inputSchema']['properties']);
        self::assertArrayHasKey('languageId', $schema['inputSchema']['properties']);

        // Verify language parameter has enum with ISO codes
        self::assertArrayHasKey('enum', $schema['inputSchema']['properties']['language']);
        self::assertContains('en', $schema['inputSchema']['properties']['language']['enum']);
        self::assertContains('de', $schema['inputSchema']['properties']['language']['enum']);

        // Verify languageId is marked as deprecated
        self::assertTrue($schema['inputSchema']['properties']['languageId']['deprecated'] ?? false);

        // Check url parameter was added
        self::assertArrayHasKey('url', $schema['inputSchema']['properties']);
    }

    /**
     * Test page with different content types
     */
    public function testPageWithDifferentContentTypes(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test Contact page which has a form
        $result = $tool->execute([
            'uid' => 6,
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Verify page information
        self::assertStringContainsString('Title: Contact', $content);

        // Verify plugin content element output
        self::assertStringContainsString('[105] Contact Form', $content);
        self::assertStringContainsString('news_pi1', $content);
    }

    /**
     * Test page tree structure (parent-child relationships)
     */
    public function testPageTreeStructure(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test child page (Team - child of About)
        $result = $tool->execute([
            'uid' => 4,
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;

        // Verify it shows the correct parent
        self::assertStringContainsString('Title: Team', $content);
        self::assertStringContainsString('Navigation Title: Our Team', $content);
        self::assertStringContainsString('Parent Page (PID): 2', $content);
    }

    /**
     * Test URL resolution with full URL
     */
    public function testUrlResolutionWithFullUrl(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with full URL for About page
        $result = $tool->execute([
            'url' => 'https://example.com/about',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve full URL: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify we got the right page
        self::assertStringContainsString('UID: 2', $content);
        self::assertStringContainsString('Title: About', $content);
    }

    /**
     * Test URL resolution with path only
     */
    public function testUrlResolutionWithPath(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with just path for Contact page
        $result = $tool->execute([
            'url' => '/contact',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify we got the right page
        self::assertStringContainsString('UID: 6', $content);
        self::assertStringContainsString('Title: Contact', $content);
    }

    /**
     * Test URL resolution with nested path
     */
    public function testUrlResolutionWithNestedPath(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with nested path for Team page (under About)
        $result = $tool->execute([
            'url' => '/about/team',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve nested path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify we got the right page
        self::assertStringContainsString('UID: 4', $content);
        self::assertStringContainsString('Title: Team', $content);
    }

    /**
     * Test URL resolution with trailing slash
     */
    public function testUrlResolutionWithTrailingSlash(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with trailing slash on path
        $result = $tool->execute([
            'url' => '/about/'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        $this->assertStringContainsString('UID: 2', $content);
        $this->assertStringContainsString('Title: About', $content);
    }

    /**
     * Test URL resolution with trailing slash on full URL
     */
    public function testUrlResolutionWithTrailingSlashFullUrl(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with trailing slash on full URL
        $result = $tool->execute([
            'url' => 'https://example.com/about/team/'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        $this->assertStringContainsString('UID: 4', $content);
        $this->assertStringContainsString('Title: Team', $content);
    }

    /**
     * Test URL resolution without leading slash
     */
    public function testUrlResolutionWithoutLeadingSlash(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test without leading slash
        $result = $tool->execute([
            'url' => 'about',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve path without leading slash: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify we got the right page
        self::assertStringContainsString('UID: 2', $content);
        self::assertStringContainsString('Title: About', $content);
    }

    /**
     * Test URL resolution for home page
     */
    public function testUrlResolutionForHomePage(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with just domain (home page)
        $result = $tool->execute([
            'url' => 'https://example.com/',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve home page URL: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Verify we got the home page
        self::assertStringContainsString('UID: 1', $content);
        self::assertStringContainsString('Title: Home', $content);

        // Also test with just /
        $result = $tool->execute([
            'url' => '/',
        ]);

        self::assertFalse($result->isError, 'Failed to resolve home page path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        self::assertStringContainsString('UID: 1', $content);
    }

    /**
     * Test URL resolution with wrong domain
     */
    public function testUrlResolutionWithWrongDomain(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with wrong domain - should fail because domain doesn't match site config
        $result = $tool->execute([
            'url' => 'https://wrong-domain.com/about',
        ]);

        self::assertTrue($result->isError, 'Expected error when using wrong domain, but got: ' . json_encode($result->jsonSerialize()));
        $errorMessage = $result->content[0]->text;
        self::assertStringContainsString('Could not resolve URL', $errorMessage);
        self::assertStringContainsString('domain does not match', $errorMessage);
    }

    /**
     * Test URL resolution with invalid path
     */
    public function testUrlResolutionWithInvalidPath(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test with non-existent path
        $result = $tool->execute([
            'url' => '/non-existent-page',
        ]);

        self::assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        self::assertStringContainsString('Could not resolve URL', $errorMessage);
    }

    /**
     * Test URL resolution with language parameter
     */
    public function testUrlResolutionWithLanguage(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test URL resolution with language ID
        $result = $tool->execute([
            'url' => '/about',
            'languageId' => 0,  // Default language
        ]);

        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('UID: 2', $content);
    }

    /**
     * Test URL generation for different pages with real site configuration
     */
    public function testRealUrlGenerationForDifferentPages(): void
    {
        $tool = $this->getService(GetPageTool::class);

        // Test Home page (root) - should have base URL
        $result = $tool->execute(['uid' => 1]);
        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('URL:', $content);
        self::assertStringContainsString('https://example.com/', $content);

        // Test Contact page
        $result = $tool->execute(['uid' => 6]);
        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('URL:', $content);
        self::assertStringContainsString('https://example.com/contact', $content);

        // Test nested page (Team under About)
        $result = $tool->execute(['uid' => 4]);
        self::assertFalse($result->isError);
        $content = $result->content[0]->text;
        self::assertStringContainsString('URL:', $content);
        self::assertStringContainsString('https://example.com/about/team', $content);
    }

    /**
     * Test getting a page that was created in workspace (no live version)
     *
     * This tests the scenario where:
     * 1. A page is created in a workspace (not yet live)
     * 2. GetPage should be able to retrieve this workspace-only page
     */
    public function testGetWorkspaceOnlyPage(): void
    {
        // Switch to workspace context
        $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        // Create a new page in the workspace using WriteTableTool
        $writeTool = $this->getService(WriteTableTool::class);
        $createResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1, // Under home page
            'data' => [
                'title' => 'Workspace Only Page',
                'doktype' => 1,
                'slug' => '/workspace-only-page',
            ],
        ]);

        self::assertFalse($createResult->isError, 'Failed to create page: ' . json_encode($createResult->jsonSerialize()));
        $createData = json_decode($createResult->content[0]->text, true);
        $newPageUid = $createData['uid'];

        // Verify we got a valid UID
        self::assertGreaterThan(0, $newPageUid, 'Should have received a valid page UID');

        // Now try to get this workspace-only page using GetPage
        $getPageTool = $this->getService(GetPageTool::class);

        $result = $getPageTool->execute([
            'uid' => $newPageUid,
        ]);

        // This is the main assertion - GetPage should find the workspace-only page
        self::assertFalse($result->isError, 'GetPage should find workspace-only page: ' . json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Verify page information is present
        self::assertStringContainsString('PAGE INFORMATION', $content);
        self::assertStringContainsString('UID: ' . $newPageUid, $content);
        self::assertStringContainsString('Title: Workspace Only Page', $content);
        self::assertStringContainsString('Parent Page (PID): 1', $content);
    }

    /**
     * Test getting a page that was created in workspace with content elements
     */
    public function testGetWorkspaceOnlyPageWithContent(): void
    {
        // Switch to workspace context
        $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        // Create a new page in the workspace
        $writeTool = $this->getService(WriteTableTool::class);
        $createPageResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => [
                'title' => 'Workspace Page With Content',
                'doktype' => 1,
                'slug' => '/workspace-page-with-content',
            ],
        ]);

        self::assertFalse($createPageResult->isError, json_encode($createPageResult->jsonSerialize()));
        $pageData = json_decode($createPageResult->content[0]->text, true);
        $newPageUid = $pageData['uid'];

        // Create content element on the new workspace page
        $createContentResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $newPageUid,
            'data' => [
                'header' => 'Workspace Content Element',
                'CType' => 'text',
                'bodytext' => 'This content was created in workspace',
            ],
        ]);

        self::assertFalse($createContentResult->isError, json_encode($createContentResult->jsonSerialize()));

        // Now get the page and verify it shows the content element
        $getPageTool = $this->getService(GetPageTool::class);

        $result = $getPageTool->execute([
            'uid' => $newPageUid,
        ]);

        self::assertFalse($result->isError, 'GetPage should find workspace page: ' . json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Verify page info
        self::assertStringContainsString('Title: Workspace Page With Content', $content);

        // Verify content element is listed
        self::assertStringContainsString('Content Elements (tt_content)', $content);
        self::assertStringContainsString('Workspace Content Element', $content);
    }

}
