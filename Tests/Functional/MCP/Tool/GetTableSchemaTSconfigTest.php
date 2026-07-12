<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test TSconfig field visibility support for GetTableSchemaTool
 *
 * This test class seeds a parsed v14 Page TSconfig object for root-level
 * schema lookups to disable selected fields globally.
 */
class GetTableSchemaTSconfigTest extends FunctionalTestCase
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

        // Import base fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);

        $this->seedRootTSconfig(
            "TCEFORM.tt_content.bodytext.disabled = 1\n"
            . "TCEFORM.tt_content.date.disabled = 1\n"
            . 'TCEFORM.pages.abstract.disabled = 1'
        );
    }

    /**
     * Test that globally disabled fields are hidden from schema
     */
    public function testHidesGloballyDisabledFields(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Extract just the FIELDS section for field presence checks
        $fieldsSection = substr((string)$content, strpos((string)$content, 'FIELDS:') ?: 0);

        // bodytext field definition should NOT appear in FIELDS section
        self::assertStringNotContainsString('- bodytext (', $fieldsSection);
        // date field definition should NOT appear in FIELDS section
        self::assertStringNotContainsString('- date (', $fieldsSection);
        self::assertStringNotContainsString('├─ date (', $fieldsSection);

        // Other fields should still appear
        self::assertStringContainsString('header', $content);
        self::assertStringContainsString('CType', $content);
    }

    /**
     * Test that non-disabled fields still appear normally
     */
    public function testNonDisabledFieldsAppearNormally(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Essential fields should still appear
        self::assertStringContainsString('header', $content);
        self::assertStringContainsString('CType', $content);
        self::assertStringContainsString('hidden', $content);
        self::assertStringContainsString('header_layout', $content);
    }

    /**
     * Test that TSconfig disabled applies to all users including admins
     */
    public function testDisabledAppliesToAdminUsers(): void
    {
        // Verify admin user is set up
        self::assertTrue($GLOBALS['BE_USER']->isAdmin());

        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr((string)$content, strpos((string)$content, 'FIELDS:') ?: 0);

        // Even for admin, bodytext field definition should be hidden
        self::assertStringNotContainsString('- bodytext (', $fieldsSection);
    }

    /**
     * Test disabled field in pages table
     */
    public function testDisabledFieldInPagesTable(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);
        $result = $tool->execute([
            'table' => 'pages',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr((string)$content, strpos((string)$content, 'FIELDS:') ?: 0);

        // abstract field definition should be hidden
        self::assertStringNotContainsString('- abstract (', $fieldsSection);
        self::assertStringNotContainsString('├─ abstract (', $fieldsSection);
        self::assertStringNotContainsString('└─ abstract (', $fieldsSection);

        // Other fields should appear
        self::assertStringContainsString('title', $content);
        self::assertStringContainsString('slug', $content);
    }

    /**
     * Test that different content types work correctly
     */
    public function testDifferentContentTypes(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Check text type - bodytext should be hidden here too
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'text',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr((string)$content, strpos((string)$content, 'FIELDS:') ?: 0);

        // bodytext field definition should be hidden
        self::assertStringNotContainsString('- bodytext (', $fieldsSection);
        self::assertStringContainsString('header', $content);
    }

    /**
     * Parse v14 Page TSconfig through the Core factory and place it in the
     * runtime cache used by BackendUtility::getPagesTSconfig(0).
     */
    private function seedRootTSconfig(string $tsConfig): void
    {
        // TsConfigTreeBuilder ignores uid=0 rootline entries, so use a
        // synthetic root page while caching the parsed result for pid=0.
        $rootLine = [
            0 => [
                'uid' => 1,
                'pid' => 0,
                'TSconfig' => $tsConfig,
                'tsconfig_includes' => '',
                'is_siteroot' => 0,
                't3ver_oid' => 0,
                't3ver_wsid' => 0,
                't3ver_state' => 0,
                't3ver_stage' => 0,
                'doktype' => 0,
                'sorting' => 0,
                'deleted' => 0,
                'hidden' => 0,
            ],
        ];

        $factory = GeneralUtility::makeInstance(PageTsConfigFactory::class);
        $pageTsConfig = $factory->create($rootLine, new NullSite(), null);

        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        $hash = 'mcp-schema-test-' . md5($tsConfig);
        $cache->set('pageTsConfig-pid-to-hash-0', $hash);
        $cache->set('pageTsConfig-hash-to-object-' . $hash, $pageTsConfig);
    }
}
