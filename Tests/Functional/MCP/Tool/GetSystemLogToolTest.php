<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetSystemLogTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class GetSystemLogToolTest extends AbstractFunctionalTest
{
    private GetSystemLogTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(GetSystemLogTool::class);
    }

    protected function loadStandardFixtures(): void
    {
        parent::loadStandardFixtures();
        $fixturesPath = __DIR__ . '/../../Fixtures/';
        $this->importCSVDataSet($fixturesPath . 'sys_log.csv');
    }

    public function testReadAllLogEntries(): void
    {
        $result = $this->tool->execute([]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('entries', $data);
        self::assertArrayHasKey('total', $data);
        self::assertGreaterThan(0, $data['total']);
    }

    public function testFilterBySeverity(): void
    {
        // severity 3 = error and above → PSR-3 level <= 3
        $result = $this->tool->execute(['severity' => 3]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['entries'] as $entry) {
            self::assertContains($entry['level'], ['emergency', 'alert', 'critical', 'error']);
        }
    }

    public function testFilterByDateRange(): void
    {
        $result = $this->tool->execute([
            'since' => '2023-11-15T00:00:00',
            'until' => '2023-11-15T01:00:00',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('entries', $data);
    }

    public function testFilterByTablename(): void
    {
        $result = $this->tool->execute(['tablename' => 'tt_content']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['entries'] as $entry) {
            if (isset($entry['tablename'])) {
                self::assertEquals('tt_content', $entry['tablename']);
            }
        }
    }

    public function testPagination(): void
    {
        $result = $this->tool->execute(['limit' => 2, 'offset' => 0]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertLessThanOrEqual(2, $data['returned']);
        self::assertEquals(2, $data['limit']);
        self::assertEquals(0, $data['offset']);
    }

    public function testEntryFormat(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertNotEmpty($data['entries']);

        $entry = $data['entries'][0];
        self::assertArrayHasKey('uid', $entry);
        self::assertArrayHasKey('timestamp', $entry);
        self::assertArrayHasKey('level', $entry);
        self::assertArrayHasKey('message', $entry);
        self::assertArrayHasKey('user', $entry);
        self::assertArrayHasKey('uid', $entry['user']);
    }
}
