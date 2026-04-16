<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\File\SearchMediaTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class SearchMediaToolTest extends AbstractFunctionalTest
{
    private SearchMediaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(SearchMediaTool::class);
    }

    protected function loadStandardFixtures(): void
    {
        parent::loadStandardFixtures();
        $fixturesPath = __DIR__ . '/../../Fixtures/';
        $this->importCSVDataSet($fixturesPath . 'sys_file.csv');
        $this->importCSVDataSet($fixturesPath . 'sys_file_metadata.csv');
    }

    public function testSearchByKeywordInTitle(): void
    {
        $result = $this->tool->execute(['keyword' => 'Team Photo']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['total']);
        self::assertNotEmpty($data['files']);

        $found = false;
        foreach ($data['files'] as $file) {
            if ($file['name'] === 'test.jpg') {
                $found = true;
                self::assertEquals('Team Photo', $file['metadata']['title']);
            }
        }
        self::assertTrue($found, 'Expected to find test.jpg in search results');
    }

    public function testSearchByKeywordInFileName(): void
    {
        $result = $this->tool->execute(['keyword' => 'logo']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['total']);

        $fileNames = array_column($data['files'], 'name');
        self::assertContains('logo.png', $fileNames);
    }

    public function testFilterByMimeType(): void
    {
        $result = $this->tool->execute(['mimeType' => 'image/']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['files'] as $file) {
            self::assertStringStartsWith('image/', $file['mimeType']);
        }
    }

    public function testFilterByExtension(): void
    {
        $result = $this->tool->execute(['extension' => 'pdf']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['total']);

        foreach ($data['files'] as $file) {
            self::assertEquals('pdf', $file['extension']);
        }
    }

    public function testFilterByMinDimensions(): void
    {
        $result = $this->tool->execute(['minWidth' => 1000, 'minHeight' => 700]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['files'] as $file) {
            if (isset($file['width'])) {
                self::assertGreaterThanOrEqual(1000, $file['width']);
            }
            if (isset($file['height'])) {
                self::assertGreaterThanOrEqual(700, $file['height']);
            }
        }
    }

    public function testPagination(): void
    {
        $result = $this->tool->execute(['mimeType' => 'image/', 'limit' => 2, 'offset' => 0]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertLessThanOrEqual(2, $data['returned']);
        self::assertEquals(2, $data['limit']);
        self::assertEquals(0, $data['offset']);
    }

    public function testNoFiltersReturnsError(): void
    {
        $result = $this->tool->execute([]);
        self::assertTrue($result->isError, 'Expected error when no filters provided');
    }

    public function testEmptyResults(): void
    {
        $result = $this->tool->execute(['keyword' => 'nonexistent_file_xyz_12345']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertEquals(0, $data['total']);
        self::assertEmpty($data['files']);
    }

    public function testCombinedFilters(): void
    {
        $result = $this->tool->execute(['keyword' => 'logo', 'mimeType' => 'image/']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertGreaterThan(0, $data['total']);
    }

    public function testFilterByFolder(): void
    {
        $result = $this->tool->execute(['folder' => '/user_upload/']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);

        foreach ($data['files'] as $file) {
            self::assertStringStartsWith('/user_upload/', $file['identifier']);
        }
    }
}
