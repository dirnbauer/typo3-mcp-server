<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test TSconfig field visibility support for GetTableSchemaTool
 *
 * This test class sets TSconfig via configurationToUseInTestInstance
 * to disable the bodytext field globally.
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

    /**
     * Set TSconfig before TYPO3 bootstraps
     */
    protected array $configurationToUseInTestInstance = [
        'BE' => [
            'defaultPageTSconfig' => '
                TCEFORM.tt_content.bodytext.disabled = 1
                TCEFORM.tt_content.date.disabled = 1
                TCEFORM.pages.abstract.disabled = 1
            ',
        ],
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
}
