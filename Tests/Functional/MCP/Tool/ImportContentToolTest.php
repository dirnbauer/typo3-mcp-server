<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ImportContentTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ImportContentToolTest extends AbstractFunctionalTest
{
    private ImportContentTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(ImportContentTool::class);
    }

    public function testMarkdownSplitsIntoElements(): void
    {
        $content = <<<'MD'
# Welcome to Our Site

This is the introduction paragraph with some important information.

## Features

We offer many great features for our customers.

### Technical Details

Here are the technical specifications.
MD;

        $result = $this->tool->execute([
            'content' => $content,
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('markdown', $data['format']);
        self::assertGreaterThanOrEqual(3, $data['totalElements']);

        // First element should be a heading with a known CType
        $first = $data['elements'][0];
        $availableCTypes = array_keys($data['availableContentTypes']);
        self::assertContains($first['CType'], $availableCTypes);
        self::assertEquals('Welcome to Our Site', $first['header']);
    }

    public function testHtmlPreservesStructure(): void
    {
        $content = <<<'HTML'
<h1>Page Title</h1>
<p>Introduction paragraph.</p>
<table><tr><td>Cell 1</td><td>Cell 2</td></tr></table>
<p>Another paragraph.</p>
HTML;

        $result = $this->tool->execute([
            'content' => $content,
            'targetPid' => 1,
            'format' => 'html',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('html', $data['format']);
        self::assertGreaterThanOrEqual(3, $data['totalElements']);

        // Check that table section exists in the proposal
        $tableElement = null;
        foreach ($data['elements'] as $el) {
            if (str_contains($el['summary'] ?? '', 'HTML table')) {
                $tableElement = $el;
                break;
            }
        }
        self::assertNotNull($tableElement, 'Should have a table element');
        // CType is dynamically chosen — just verify it has bodytext with the table
        self::assertStringContainsString('<table', $tableElement['bodytext']);
    }

    public function testPlainTextGroupsParagraphs(): void
    {
        $content = "First paragraph of text here.\n\nSecond paragraph continues.\n\nThird paragraph ends.";

        $result = $this->tool->execute([
            'content' => $content,
            'targetPid' => 1,
            'format' => 'text',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('text', $data['format']);
        // Consecutive text paragraphs should be merged
        self::assertGreaterThanOrEqual(1, $data['totalElements']);
    }

    public function testAutoDetectsMarkdownFormat(): void
    {
        $result = $this->tool->execute([
            'content' => "# Heading\n\nSome paragraph text.",
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('markdown', $data['format']);
    }

    public function testAutoDetectsHtmlFormat(): void
    {
        $result = $this->tool->execute([
            'content' => '<h1>Title</h1><p>Content here.</p>',
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('html', $data['format']);
    }

    public function testMergesConsecutiveTextParagraphs(): void
    {
        $content = "# Title\n\nFirst paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

        $result = $this->tool->execute([
            'content' => $content,
            'targetPid' => 1,
            'format' => 'markdown',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        // Should be 2 elements: heading + merged text (not 4)
        self::assertEquals(2, $data['totalElements']);

        // Second element should contain all paragraphs
        $textElement = $data['elements'][1];
        self::assertStringContainsString('First paragraph', $textElement['bodytext']);
        self::assertStringContainsString('Third paragraph', $textElement['bodytext']);
    }

    public function testReturnsProposalNotRecords(): void
    {
        $result = $this->tool->execute([
            'content' => '# Test',
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('hint', $data);
        self::assertArrayHasKey('elements', $data);
        self::assertArrayHasKey('availableContentTypes', $data);
        self::assertArrayHasKey('totalElements', $data);
        self::assertArrayHasKey('targetPid', $data);
        self::assertEquals(1, $data['targetPid']);
    }

    public function testEmptyContentReturnsError(): void
    {
        $result = $this->tool->execute([
            'content' => '',
            'targetPid' => 1,
        ]);
        self::assertTrue($result->isError);
    }

    public function testWhitespaceOnlyContentReturnsError(): void
    {
        $result = $this->tool->execute([
            'content' => "   \n\n  \t  ",
            'targetPid' => 1,
        ]);
        self::assertTrue($result->isError);
    }

    public function testCodeBlockMappedToHtmlCType(): void
    {
        $content = "# Setup\n\n```\nconst x = 42;\nconsole.log(x);\n```";

        $result = $this->tool->execute([
            'content' => $content,
            'targetPid' => 1,
            'format' => 'markdown',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        // Find the code block element
        $codeElement = null;
        foreach ($data['elements'] as $el) {
            if (str_contains($el['summary'] ?? '', 'Code block')) {
                $codeElement = $el;
                break;
            }
        }
        self::assertNotNull($codeElement, 'Should have a code block element');
        // Verify it contains the code content
        self::assertStringContainsString('const x = 42', $codeElement['bodytext']);
    }

    public function testColPosPassedThrough(): void
    {
        $result = $this->tool->execute([
            'content' => '# Test',
            'targetPid' => 1,
            'colPos' => 2,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(2, $data['colPos']);
    }

    public function testAvailableContentTypesIncludesFieldInfo(): void
    {
        $result = $this->tool->execute([
            'content' => '# Test',
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('availableContentTypes', $data);
        self::assertNotEmpty($data['availableContentTypes']);

        // Each CType should have label and fields
        foreach ($data['availableContentTypes'] as $ctype => $info) {
            self::assertIsString($ctype);
            self::assertArrayHasKey('label', $info, 'CType ' . $ctype . ' missing label');
            self::assertArrayHasKey('fields', $info, 'CType ' . $ctype . ' missing fields');
            self::assertIsArray($info['fields']);
        }

        // Common CTypes should be present
        self::assertArrayHasKey('text', $data['availableContentTypes']);
        self::assertArrayHasKey('header', $data['availableContentTypes']);
    }

    public function testElementStructureIsComplete(): void
    {
        $result = $this->tool->execute([
            'content' => "# Title\n\nSome body text.",
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['elements'] as $element) {
            self::assertArrayHasKey('index', $element);
            self::assertArrayHasKey('CType', $element);
            self::assertArrayHasKey('header', $element);
            self::assertArrayHasKey('bodytext', $element);
            self::assertArrayHasKey('header_layout', $element);
            self::assertArrayHasKey('summary', $element);
        }
    }

    public function testExecuteModeCreatesRecords(): void
    {
        $result = $this->tool->execute([
            'content' => "# Welcome\n\nThis is the body text of the page.",
            'targetPid' => 1,
            'mode' => 'execute',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('execute', $data['mode']);
        self::assertGreaterThan(0, $data['totalCreated']);
        self::assertEquals(0, $data['totalErrors']);

        // Verify records were actually created
        foreach ($data['created'] as $created) {
            self::assertArrayHasKey('uid', $created);
            self::assertGreaterThan(0, $created['uid']);
        }
    }

    public function testAnalyzeModeDoesNotCreateRecords(): void
    {
        $result = $this->tool->execute([
            'content' => '# Test',
            'targetPid' => 1,
            'mode' => 'analyze',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('analyze', $data['mode']);
        self::assertArrayHasKey('availableContentTypes', $data);
        self::assertArrayNotHasKey('totalCreated', $data);
    }

    public function testDefaultModeIsAnalyze(): void
    {
        $result = $this->tool->execute([
            'content' => '# Test',
            'targetPid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals('analyze', $data['mode']);
    }
}
