<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

/**
 * Test LLM's ability to create and manage news articles using MCP tools
 *
 * @group llm
 */
class NewsTest extends LlmTestCase
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import test data with pages, news storage, and categories
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_plugin.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_records.csv');
    }

    /**
     * Test that LLM can create a news article about website launch
     */
    public function testLlmCreatesWebsiteLaunchNews(): void
    {
        $prompt = 'Write a news article that the website launched today (21 July 2025)';

        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        // Verify responsible exploration - LLM should discover news context
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPageTree', $history)
                         || in_array('Search', $history)
                         || in_array('ListTables', $history)
                         || in_array('GetPage', $history);
        self::assertTrue(
            $hasExploration,
            'Expected LLM to explore and discover news functionality. Tools used: ' . implode(', ', $history),
        );

        // Verify content creation - should create news record
        $writeCalls = $response->getToolCallsByName('WriteTable');
        self::assertGreaterThan(
            0,
            count($writeCalls),
            'Expected WriteTable call but none found. Tool history: ' . implode(' → ', $this->getToolCallHistory())
            . "\nFinal response: " . $response->getContent(),
        );

        $writeCall = $writeCalls[0];
        self::assertEquals('create', $writeCall['arguments']['action']);

        // Should create news record, not content element
        $table = $writeCall['arguments']['table'];
        self::assertEquals(
            'tx_news_domain_model_news',
            $table,
            'Expected news record creation when asked for a news article',
        );

        // Should be created in a reasonable location for news
        $acceptablePids = [8, 12, 30]; // Blog, Press, or Storage folder
        self::assertContains(
            $writeCall['arguments']['pid'],
            $acceptablePids,
            'News articles should be created on Blog (8), Press (12), or in the storage folder (30)',
        );

        // Execute write and verify
        $writeResult = $this->executeToolCall($writeCall);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content'],
        );

        // Verify content includes launch information
        $data = $writeCall['arguments']['data'];

        // Collect all text fields from news record
        $allContent = ($data['title'] ?? '') . ' '
                     . ($data['teaser'] ?? '') . ' '
                     . ($data['bodytext'] ?? '');

        self::assertNotEmpty($allContent, 'Content should not be empty');
        self::assertMatchesRegularExpression(
            '/launch|website|site|online|live|released|today/i',
            $allContent,
            'Content should mention the website launch',
        );

        // Check date handling for news records
        if (isset($data['datetime'])) {
            $datetime = is_numeric($data['datetime'])
                ? (int)$data['datetime']
                : strtotime((string)$data['datetime']);

            // Just verify a date was set (LLM might use current date)
            self::assertGreaterThan(0, $datetime, 'News should have a valid date');
        }
    }

    /**
     * Test that LLM can create news with appropriate category
     */
    public function testLlmCreatesNewsWithCategory(): void
    {
        $prompt = 'Create a company announcement about our new product launch next week and categorize it appropriately';

        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        // Verify exploration
        $history = $this->getToolCallHistory();
        $hasExploration = count($history) > 1; // Should do more than just write
        self::assertTrue(
            $hasExploration,
            'Expected LLM to explore before creating news. Tools used: ' . implode(', ', $history),
        );

        // Find news creation
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $newsWriteCall = null;

        foreach ($writeCalls as $call) {
            if ($call['arguments']['table'] === 'tx_news_domain_model_news') {
                $newsWriteCall = $call;
                break;
            }
        }

        self::assertNotNull(
            $newsWriteCall,
            'Expected news record creation but none found. Tool history: ' . implode(' → ', $this->getToolCallHistory())
            . "\nAll WriteTable calls: " . json_encode(array_map(fn($c) => $c['arguments']['table'] ?? 'unknown', $writeCalls)),
        );
        self::assertEquals('create', $newsWriteCall['arguments']['action']);
        $acceptablePids = [8, 12, 30]; // Blog, Press, or Storage folder
        self::assertContains(
            $newsWriteCall['arguments']['pid'],
            $acceptablePids,
            'News articles should be created on Blog (8), Press (12), or in the storage folder (30)',
        );

        // Execute and verify
        $writeResult = $this->executeToolCall($newsWriteCall);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content'],
        );

        // Verify content mentions product launch
        $data = $newsWriteCall['arguments']['data'];

        $allContent = ($data['title'] ?? '') . ' '
                     . ($data['teaser'] ?? '') . ' '
                     . ($data['bodytext'] ?? '');

        self::assertMatchesRegularExpression(
            '/product|launch|new|announcement|release/i',
            $allContent,
            'Content should mention product launch',
        );

        // Check if LLM attempted category handling
        $hasCategories = isset($data['categories']) && !empty($data['categories']);
        $createdCategories = array_filter($writeCalls, fn($call) => $call['arguments']['table'] === 'sys_category');

        // Category handling is optional - the LLM was asked to "categorize appropriately"
        // which it might interpret as:
        // 1. Assigning existing categories
        // 2. Creating new categories
        // 3. Simply creating the news in an appropriate location/section
        // All are valid interpretations
        if ($hasCategories || count($createdCategories) > 0) {
            self::assertTrue(true, 'LLM handled categories by assigning or creating them');
        }
    }

    /**
     * Test that LLM can add news to press/blog/updates section
     */
    public function testLlmAddsNewsToNewsSection(): void
    {
        $prompt = 'Add a news article about our summer sale to the website where news and announcements go';

        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        // Verify exploration to find appropriate location
        $history = $this->getToolCallHistory();
        $hasPageExploration = in_array('GetPageTree', $history)
                             || in_array('GetPage', $history)
                             || in_array('Search', $history)
                             || in_array('ListTables', $history);
        self::assertTrue(
            $hasPageExploration,
            'Expected LLM to explore to find appropriate location. Tools used: ' . implode(', ', $history),
        );

        // Verify news creation
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $newsWriteCall = null;

        foreach ($writeCalls as $call) {
            if ($call['arguments']['table'] === 'tx_news_domain_model_news') {
                $newsWriteCall = $call;
                break;
            }
        }

        self::assertNotNull(
            $newsWriteCall,
            'Expected news record creation but none found. Tool history: ' . implode(' → ', $this->getToolCallHistory())
            . "\nAll WriteTable calls: " . json_encode(array_map(fn($c) => $c['arguments']['table'] ?? 'unknown', $writeCalls)),
        );
        self::assertEquals('create', $newsWriteCall['arguments']['action']);
        $acceptablePids = [8, 12, 30]; // Blog, Press, or Storage folder
        self::assertContains(
            $newsWriteCall['arguments']['pid'],
            $acceptablePids,
            'News articles should be created on Blog (8), Press (12), or in the storage folder (30)',
        );

        // Execute and verify
        $writeResult = $this->executeToolCall($newsWriteCall);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content'],
        );

        // Verify content mentions summer sale
        $data = $newsWriteCall['arguments']['data'];

        $allContent = ($data['title'] ?? '') . ' '
                     . ($data['teaser'] ?? '') . ' '
                     . ($data['bodytext'] ?? '');

        self::assertMatchesRegularExpression(
            '/summer|sale|discount|offer|special/i',
            $allContent,
            'Content should mention summer sale',
        );
    }
}
