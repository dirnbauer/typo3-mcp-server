<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\ToolContextBenchmarkService;
use PHPUnit\Framework\TestCase;

final class ToolContextBenchmarkServiceTest extends TestCase
{
    public function testSchemaArmsAndOversizedResponsesAreMeasuredDeterministically(): void
    {
        $service = new ToolContextBenchmarkService();
        $raw = [
            'LargeTool' => ['description' => str_repeat('verbose ', 80), 'inputSchema' => ['type' => 'object']],
            'SmallTool' => ['description' => 'small', 'inputSchema' => ['type' => 'object']],
        ];
        $optimized = [
            'LargeTool' => ['description' => str_repeat('focused ', 20), 'inputSchema' => ['type' => 'object']],
            'SmallTool' => $raw['SmallTool'],
        ];

        $report = $service->benchmarkSchemas($raw, $optimized, 100, 2.0);
        $response = $service->measureResponse('LargeTool', ['result' => str_repeat('x', 300)], 200, 2.0);

        self::assertGreaterThan($report['schema']['armBOptimized']['bytes'], $report['schema']['armAFull']['bytes']);
        self::assertGreaterThan(0, $report['schema']['savings']['bytes']);
        self::assertContains('LargeTool', $report['oversizedSchemas']);
        self::assertSame(2.0, $report['estimation']['bytesPerToken']);
        self::assertTrue($response['oversized']);
        self::assertGreaterThan(150, $response['estimatedTokens']);
    }

    public function testBaselineComparisonHighlightsSchemaAndResponseGrowth(): void
    {
        $service = new ToolContextBenchmarkService();
        $current = [
            'schema' => ['armBOptimized' => ['bytes' => 1200]],
            'responses' => [['tool' => 'ApplicationInfo', 'bytes' => 700]],
        ];
        $baseline = [
            'schema' => ['armBOptimized' => ['bytes' => 1000]],
            'responses' => [['tool' => 'ApplicationInfo', 'bytes' => 900]],
        ];

        $comparison = $service->compareWithBaseline($current, $baseline);

        self::assertSame(200, $comparison['optimizedSchemaBytesDelta']);
        self::assertSame(-200, $comparison['responseBytesDelta']['ApplicationInfo']);
    }
}
