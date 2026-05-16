<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test how different LLMs use the WriteTable data parameter to correct spelling errors.
 *
 * The data parameter accepts either full string values (complete replacement) or arrays
 * of {search, replace} operations for targeted text modifications. Both approaches are valid.
 *
 * @group llm
 */
class WriteTableSearchReplaceTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Import standard pages and the content with spelling errors
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/backend_layout.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/search_replace_content.csv');
    }

    /**
     * Content element 200 has header "Welcom to Our Compnay" (two typos).
     * The LLM should read the content, identify the errors, and use WriteTable
     * with either search-and-replace arrays or full string values to fix them.
     */
    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "fix spelling in header about welcome/company" → explores, then WriteTable(update) fixing "Welcom"→"Welcome" and "Compnay"→"Company"')]
    public function testLlmFixesHeaderSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            self::markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'There are spelling errors in the header of a content element on the home page that says something about "welcome" and "company". Please find and fix the spelling mistakes.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        // Verify exploration happened
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history)
            || in_array('GetPageTree', $history)
            || in_array('ReadTable', $history)
            || in_array('Search', $history);
        self::assertTrue(
            $hasExploration,
            "[$modelKey] Expected LLM to explore content before fixing. Tools used: " . implode(', ', $history),
        );

        // Verify WriteTable was called
        $writeCalls = $response->getToolCallsByName('WriteTable');
        self::assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected WriteTable call. History: " . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent(),
        );

        $writeCall = $writeCalls[0]['arguments'];

        self::assertEquals(
            'update',
            $writeCall['action'],
            "[$modelKey] Expected update action",
        );

        self::assertArrayHasKey(
            'data',
            $writeCall,
            "[$modelKey] Expected data parameter in WriteTable call",
        );

        // Execute the tool call and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content'],
        );

        // Check that the header field was addressed
        $data = $writeCall['data'];
        self::assertArrayHasKey(
            'header',
            $data,
            "[$modelKey] Expected header field in data",
        );

        $headerValue = $data['header'];

        if (is_string($headerValue)) {
            // Full replacement — verify it contains the corrected words
            self::assertStringContainsString(
                'Welcome',
                $headerValue,
                "[$modelKey] Updated header should contain 'Welcome'",
            );
            self::assertStringContainsString(
                'Company',
                $headerValue,
                "[$modelKey] Updated header should contain 'Company'",
            );
        } elseif (is_array($headerValue)) {
            // Search-and-replace operations
            $fixedWelcome = false;
            $fixedCompany = false;
            foreach ($headerValue as $op) {
                if (stripos($op['search'] ?? '', 'Welcom') !== false && stripos($op['replace'] ?? '', 'Welcome') !== false) {
                    $fixedWelcome = true;
                }
                if (stripos($op['search'] ?? '', 'Compnay') !== false && stripos($op['replace'] ?? '', 'Company') !== false) {
                    $fixedCompany = true;
                }
            }

            self::assertTrue(
                $fixedWelcome,
                "[$modelKey] Expected 'Welcom' -> 'Welcome' correction. Got: "
                    . json_encode($headerValue),
            );
            self::assertTrue(
                $fixedCompany,
                "[$modelKey] Expected 'Compnay' -> 'Company' correction. Got: "
                    . json_encode($headerValue),
            );
        } else {
            self::fail("[$modelKey] header field value is neither string nor array: " . gettype($headerValue));
        }
    }

    /**
     * Content element 201 has bodytext with many typos.
     */
    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "fix spelling in Our Servces content element" → explores, then WriteTable(update) fixing header to "Services" and bodytext typos')]
    public function testLlmFixesBodytextSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            self::markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'The "Our Servces" content element on the home page has many spelling errors in both the header and body text. Please fix all the spelling mistakes.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        self::assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected WriteTable call. History: " . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent(),
        );

        $writeCall = $writeCalls[0]['arguments'];
        self::assertEquals(
            'update',
            $writeCall['action'],
            "[$modelKey] Expected update action",
        );

        // Execute and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content'],
        );

        $data = $this->extractWriteData($writeCall);

        // Verify header was addressed (either as string or search/replace)
        if (isset($data['header'])) {
            if (is_string($data['header'])) {
                self::assertStringContainsString(
                    'Services',
                    $data['header'],
                    "[$modelKey] Updated header should contain 'Services'",
                );
            } elseif (is_array($data['header'])) {
                $headerFixed = false;
                foreach ($data['header'] as $op) {
                    if (stripos($op['replace'] ?? '', 'Services') !== false) {
                        $headerFixed = true;
                    }
                }
                self::assertTrue(
                    $headerFixed,
                    "[$modelKey] Expected header to be corrected to 'Services'",
                );
            }
        }

        // Verify bodytext was addressed
        if (isset($data['bodytext'])) {
            if (is_string($data['bodytext'])) {
                self::assertStringNotContainsString(
                    'devlopment',
                    $data['bodytext'],
                    "[$modelKey] Should fix 'devlopment' typo",
                );
                self::assertStringNotContainsString(
                    'dixital',
                    $data['bodytext'],
                    "[$modelKey] Should fix 'dixital' typo",
                );
            } elseif (is_array($data['bodytext'])) {
                self::assertGreaterThan(
                    0,
                    count($data['bodytext']),
                    "[$modelKey] Expected at least one bodytext replacement",
                );
            }
        }
    }

    /**
     * The LLM should discover the content, identify errors, and fix them.
     */
    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "there are spelling mistakes on the home page, find and fix them" → explores page content via GetPage/ReadTable, then WriteTable(update) to fix errors')]
    public function testLlmFindsAndFixesTyposNaturally(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            self::markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'I noticed there are some spelling mistakes on the home page. Can you find and fix them?';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8, // Allow more iterations for exploration
        );

        // The LLM should have explored and found content with typos
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history)
            || in_array('GetPageTree', $history)
            || in_array('ReadTable', $history);
        self::assertTrue(
            $hasExploration,
            "[$modelKey] Expected LLM to explore page content. Tools used: " . implode(', ', $history),
        );

        // Should have called WriteTable to fix something
        $writeCalls = $response->getToolCallsByName('WriteTable');
        self::assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected at least one WriteTable call to fix spelling errors. "
                . 'History: ' . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent(),
        );

        // Execute the first write call and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        self::assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content'],
        );
    }
}
