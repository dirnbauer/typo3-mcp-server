<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ContentAuditTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ContentAuditToolTest extends AbstractFunctionalTest
{
    private ContentAuditTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(ContentAuditTool::class);
    }

    public function testRunAllChecks(): void
    {
        $result = $this->tool->execute(['rootPageId' => 1]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('rootPageId', $data);
        self::assertArrayHasKey('checksRun', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('issues', $data);
        self::assertArrayHasKey('totalIssues', $data);
        self::assertArrayHasKey('pagesScanned', $data);
        self::assertEquals(1, $data['rootPageId']);
        self::assertGreaterThan(0, $data['pagesScanned']);
    }

    public function testRunSpecificCheck(): void
    {
        $result = $this->tool->execute([
            'rootPageId' => 1,
            'checks' => ['missing_meta_description'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(['missing_meta_description'], $data['checksRun']);
        self::assertArrayHasKey('missing_meta_description', $data['issues']);
    }

    public function testMissingMetaDescription(): void
    {
        $result = $this->tool->execute([
            'rootPageId' => 1,
            'checks' => ['missing_meta_description'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        // Standard test fixtures have pages without meta descriptions
        $issues = $data['issues']['missing_meta_description'] ?? [];
        foreach ($issues as $issue) {
            self::assertArrayHasKey('pageUid', $issue);
            self::assertArrayHasKey('pageTitle', $issue);
            self::assertArrayHasKey('issue', $issue);
        }
    }

    public function testDepthLimit(): void
    {
        $result = $this->tool->execute([
            'rootPageId' => 1,
            'depth' => 1,
            'checks' => ['missing_meta_description'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        // With depth 1, we should scan fewer pages than with default depth
        self::assertGreaterThan(0, $data['pagesScanned']);
    }

    public function testLimitPerCheck(): void
    {
        $result = $this->tool->execute([
            'rootPageId' => 1,
            'limit' => 1,
            'checks' => ['missing_meta_description'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        $issues = $data['issues']['missing_meta_description'] ?? [];
        self::assertLessThanOrEqual(1, count($issues));
    }

    public function testMultipleChecks(): void
    {
        $result = $this->tool->execute([
            'rootPageId' => 1,
            'checks' => ['missing_meta_description', 'missing_page_title'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertCount(2, $data['checksRun']);
        self::assertArrayHasKey('missing_meta_description', $data['issues']);
        self::assertArrayHasKey('missing_page_title', $data['issues']);
    }

    public function testSummaryCountsMatchIssues(): void
    {
        $result = $this->tool->execute(['rootPageId' => 1]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        $calculatedTotal = 0;
        foreach ($data['summary'] as $check => $count) {
            self::assertCount($count, $data['issues'][$check] ?? []);
            $calculatedTotal += $count;
        }
        self::assertEquals($calculatedTotal, $data['totalIssues']);
    }
}
