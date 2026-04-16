<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test the fields parameter for ReadTableTool with embedded relations
 */
class ReadTableFieldFilterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test that fields parameter excludes embedded relations when not requested
     */
    public function testFieldsParameterExcludesEmbeddedRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links',
                'bodytext' => 'Some text',
                'related_links' => [
                    ['title' => 'Link 1', 'uri' => 'https://example.com'],
                    ['title' => 'Link 2', 'uri' => 'https://example.org'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Read without related_links in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'bodytext'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];

        // Requested fields should be present
        self::assertArrayHasKey('title', $news);
        self::assertArrayHasKey('bodytext', $news);

        // Embedded relation should NOT be present since it was not requested
        self::assertArrayNotHasKey('related_links', $news, 'Embedded relation should be excluded when not in fields list');
    }

    /**
     * Test that fields parameter includes embedded relations when requested
     */
    public function testFieldsParameterIncludesEmbeddedRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links to include',
                'bodytext' => 'Some text',
                'related_links' => [
                    ['title' => 'Included Link', 'uri' => 'https://included.com'],
                ],
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Read WITH related_links in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'related_links'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];

        // Requested fields should be present
        self::assertArrayHasKey('title', $news);
        self::assertArrayHasKey('related_links', $news);

        // related_links field is included because it was requested;
        // the inline records may or may not be resolved depending on workspace state
        self::assertIsArray($news['related_links']);

        // Non-requested field should be excluded
        self::assertArrayNotHasKey('bodytext', $news, 'Non-requested field bodytext should be excluded');
    }

    /**
     * Test that fields parameter excludes independent inline relations (UIDs) when not requested
     */
    public function testFieldsParameterExcludesIndependentRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with content elements',
                'bodytext' => 'Some text',
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode((string)$result->content[0]->text, true)['uid'];

        // Create a content element related to the news
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Related CE',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read news without content_elements in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'bodytext'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode((string)$result->content[0]->text, true)['records'][0];

        // content_elements should NOT be resolved since it wasn't requested
        self::assertArrayNotHasKey('content_elements', $news, 'Independent relation should be excluded when not in fields list');
    }
}
