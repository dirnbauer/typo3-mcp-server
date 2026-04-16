<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\Traits\GetServiceTrait;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaNewsTest extends FunctionalTestCase
{
    use GetServiceTrait;
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import backend user fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user
        $this->setUpBackendUser(1);
    }

    /**
     * Test that news bodytext field shows richtext and typolink support
     */
    public function testNewsBodytextShowsRichtextAndTypolinkSupport(): void
    {
        $tool = $this->getService(GetTableSchemaTool::class);

        // Get schema for news table
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news',
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Verify bodytext field is present
        self::assertStringContainsString('bodytext', $content);

        // Verify richtext indicator is shown
        self::assertMatchesRegularExpression('/bodytext.*\[Richtext\/HTML\]/', $content);

        // Verify typolink support is indicated
        self::assertMatchesRegularExpression('/bodytext.*\[Supports typolinks/', $content);

        // Verify typolink examples are included
        self::assertStringContainsString('t3://page?uid=123', $content);
        self::assertStringContainsString('t3://record?identifier=table&uid=456', $content);
        self::assertStringContainsString('t3://file?uid=789', $content);
        self::assertStringContainsString('https://example.com', $content);
        self::assertStringContainsString('mailto:email@example.com', $content);
    }
}
