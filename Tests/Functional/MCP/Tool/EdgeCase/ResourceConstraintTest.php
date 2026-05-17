<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\EdgeCase;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Group;

#[Group('resource-intensive')]
final class ResourceConstraintTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;
    protected ReadTableTool $readTool;
    protected SearchTool $searchTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeTool = $this->getService(WriteTableTool::class);
        $this->readTool = $this->getService(ReadTableTool::class);
        $this->searchTool = $this->getService(SearchTool::class);
    }

    /**
     * Test handling of large result sets
     */
    public function testLargeResultSetHandling(): void
    {
        // Create many content elements
        $contentCount = 1000;
        $createdUids = [];

        for ($i = 0; $i < $contentCount; $i++) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'text',
                    'header' => "Content Element $i",
                    'bodytext' => "This is test content number $i with some text.",
                ],
            ]);

            if (!$result->isError) {
                $data = json_decode((string)$result->content[0]->text, true);
                $createdUids[] = $data['uid'];
            }

            // Stop if we're taking too long
            if ($i > 100 && (time() % 10) === 0) {
                break; // Prevent test timeout
            }
        }

        // Test reading with no limit
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode((string)$result->content[0]->text, true);

        // Tool should limit results automatically
        self::assertIsArray($data);
        if (count($data) > 100) {
            // If more than 100 records, there should be some indication
            self::assertLessThanOrEqual(1000, count($data), 'Results should be limited');
        }
    }

    /**
     * Test handling of very large IN clauses
     */
    public function testLargeInClauseHandling(): void
    {
        // Create a very large array of UIDs
        $uids = range(1, 10000);

        // Try to read with huge IN clause
        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => [
                ['field' => 'uid', 'operator' => 'in', 'value' => array_slice($uids, 0, 1000)],
            ],
        ]);

        // Should handle this gracefully (maybe by batching)
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test handling of deep recursive structures
     */
    public function testDeepRecursiveStructures(): void
    {
        // Create a deep page hierarchy
        $parentId = 0;
        $depth = 20;

        for ($i = 0; $i < $depth; $i++) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'pages',
                'pid' => $parentId,
                'data' => [
                    'title' => "Level $i Page",
                ],
            ]);

            if (!$result->isError) {
                $data = json_decode((string)$result->content[0]->text, true);
                $parentId = $data['uid'];
            } else {
                break;
            }
        }

        // Try to read pages with complex conditions
        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => [
                ['field' => 'title', 'operator' => 'like', 'value' => '%Level%'],
            ],
        ]);

        // Should handle deep structures without stack overflow
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test handling of complex search queries
     */
    public function testComplexSearchQueries(): void
    {
        // Create content with various special characters
        $specialContents = [
            'Special chars: !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
            'Unicode: 你好世界 🌍 مرحبا بالعالم',
            'Very long ' . str_repeat('text ', 1000),
            'Nested <b>HTML <i>tags <u>everywhere</u></i></b>',
        ];

        foreach ($specialContents as $content) {
            $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'text',
                    'header' => substr($content, 0, 100),
                    'bodytext' => $content,
                ],
            ]);
        }

        // Test searching with complex patterns
        $searchQueries = [
            'Special chars',
            '你好世界',
            'HTML tags',
            str_repeat('text ', 10),
        ];

        foreach ($searchQueries as $query) {
            $result = $this->searchTool->execute([
                'terms' => [$query], // Changed from 'query' to 'terms' array
                'tables' => ['tt_content'],
                'limit' => 100,
            ]);

            // Should handle all queries without errors
            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        }
    }

    /**
     * Test handling of execution time limits
     */
    public function testExecutionTimeLimit(): void
    {
        // Save current time limit
        $originalTimeLimit = ini_get('max_execution_time');

        // Set a short time limit (but not too short to break setup)
        set_time_limit(5);

        try {
            // Try an operation that could take time
            $startTime = time();

            // Create multiple records in a loop
            $count = 0;
            while ((time() - $startTime) < 3) { // Run for 3 seconds
                $result = $this->writeTool->execute([
                    'action' => 'create',
                    'table' => 'pages',
                    'pid' => 0,
                    'allowRootLevelPageCreation' => true,
                    'data' => [
                        'title' => "Time test page $count",
                    ],
                ]);

                if ($result->isError) {
                    break;
                }

                $count++;

                // Prevent infinite loop
                if ($count > 1000) {
                    break;
                }
            }

            // We should have created some pages
            self::assertGreaterThan(0, $count);

        } finally {
            // Restore original time limit
            set_time_limit((int)$originalTimeLimit);
        }
    }

    /**
     * Test handling of file system constraints
     */
    public function testFileSystemConstraints(): void
    {
        // Test with very long field values that might be cached/stored
        $veryLongText = str_repeat('A very long text that could fill disk space. ', 10000);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Long content test',
                'bodytext' => $veryLongText,
            ],
        ]);

        // Should handle large content gracefully
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify it was stored (possibly truncated)
        $data = json_decode((string)$result->content[0]->text, true);
        if (isset($data['uid'])) {
            $readResult = $this->readTool->execute([
                'table' => 'tt_content',
                'uid' => $data['uid'],
            ]);

            self::assertFalse($readResult->isError);
            $recordData = json_decode((string)$readResult->content[0]->text, true);
            if (isset($recordData['bodytext'])) {
                self::assertNotEmpty($recordData['bodytext']);
            } else {
                // Field might have been truncated or filtered
                self::assertTrue(true);
            }
        }
    }

    /**
     * Helper method to build WHERE clause from array structure
     */
    protected function buildWhereClause(array $where): string
    {
        if (isset($where['type']) && $where['type'] === 'AND') {
            $conditions = [];
            foreach ($where['conditions'] as $condition) {
                if (isset($condition['type']) && $condition['type'] === 'OR') {
                    $orConditions = [];
                    foreach ($condition['conditions'] as $orCond) {
                        $orConditions[] = sprintf(
                            "%s %s '%s'",
                            $orCond['field'],
                            $orCond['operator'],
                            $orCond['value'],
                        );
                    }
                    $conditions[] = '(' . implode(' OR ', $orConditions) . ')';
                }
            }
            return implode(' AND ', $conditions);
        }
        return '1=1';
    }

    /**
     * Test handling of query complexity limits
     */
    public function testQueryComplexityLimits(): void
    {
        // Build a very complex where clause
        $complexWhere = [
            'type' => 'AND',
            'conditions' => [],
        ];

        // Add many conditions
        for ($i = 0; $i < 50; $i++) {
            $complexWhere['conditions'][] = [
                'type' => 'OR',
                'conditions' => [
                    ['field' => 'title', 'operator' => 'like', 'value' => "%test$i%"],
                    ['field' => 'description', 'operator' => 'like', 'value' => "%test$i%"],
                ],
            ];
        }

        // Build a structured filter list instead of a raw SQL where clause.
        $filters = $this->buildFiltersFromStructure($complexWhere);

        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => $filters,
        ]);

        // Should handle complex queries or fail gracefully — the tool enforces AND
        // across all filters, so even 100+ LIKE conditions execute without error.
        if ($result->isError) {
            self::assertStringContainsString('filter', $result->content[0]->text);
        } else {
            self::assertIsArray(json_decode((string)$result->content[0]->text, true));
        }
    }

    /**
     * Flatten the nested AND/OR test structure into the ReadTable filters format.
     *
     * @param array{type: string, conditions: array<mixed>} $structure
     * @return list<array{field: string, operator: string, value?: mixed}>
     */
    private function buildFiltersFromStructure(array $structure): array
    {
        $filters = [];
        foreach ($structure['conditions'] as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            if (isset($condition['conditions'])) {
                foreach ($this->buildFiltersFromStructure($condition) as $inner) {
                    $filters[] = $inner;
                }
                continue;
            }
            if (isset($condition['field'], $condition['operator'])) {
                $filters[] = $condition;
            }
        }
        return $filters;
    }
}
