<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

/** Deterministic A/B measurements for MCP schema tax and result payload size. */
final class ToolContextBenchmarkService
{
    /**
     * @param array<string, array<string, mixed>> $rawSchemas
     * @param array<string, array<string, mixed>> $optimizedSchemas
     * @return array{
     *   estimation: array{bytesPerToken: float, note: string},
     *   budgets: array{schemaBytesPerTool: int},
     *   schema: array{
     *     toolCount: int,
     *     armAFull: array{bytes: int, estimatedTokens: int},
     *     armBOptimized: array{bytes: int, estimatedTokens: int},
     *     savings: array{bytes: int, estimatedTokens: int, percent: float}
     *   },
     *   tools: list<array{
     *     name: string,
     *     fullBytes: int,
     *     optimizedBytes: int,
     *     savingsBytes: int,
     *     estimatedOptimizedTokens: int,
     *     overBudget: bool
     *   }>,
     *   oversizedSchemas: list<string>
     * }
     */
    public function benchmarkSchemas(
        array $rawSchemas,
        array $optimizedSchemas,
        int $schemaBudget = 4096,
        float $bytesPerToken = 2.42,
    ): array {
        $names = array_values(array_unique([...array_keys($rawSchemas), ...array_keys($optimizedSchemas)]));
        sort($names, SORT_STRING);

        $fullCatalog = [];
        $optimizedCatalog = [];
        $tools = [];
        $oversized = [];
        foreach ($names as $name) {
            $raw = $rawSchemas[$name] ?? [];
            $optimized = $optimizedSchemas[$name] ?? $raw;
            $fullEntry = ['name' => $name, ...$raw];
            $optimizedEntry = ['name' => $name, ...$optimized];
            $fullCatalog[] = $fullEntry;
            $optimizedCatalog[] = $optimizedEntry;
            $fullMeasure = $this->measure($fullEntry, $bytesPerToken);
            $optimizedMeasure = $this->measure($optimizedEntry, $bytesPerToken);
            $tool = [
                'name' => $name,
                'fullBytes' => $fullMeasure['bytes'],
                'optimizedBytes' => $optimizedMeasure['bytes'],
                'savingsBytes' => $fullMeasure['bytes'] - $optimizedMeasure['bytes'],
                'estimatedOptimizedTokens' => $optimizedMeasure['estimatedTokens'],
                'overBudget' => $optimizedMeasure['bytes'] > $schemaBudget,
            ];
            if ($tool['overBudget']) {
                $oversized[] = $name;
            }
            $tools[] = $tool;
        }
        usort($tools, static fn(array $left, array $right): int => $right['optimizedBytes'] <=> $left['optimizedBytes']);

        $fullMeasure = $this->measure($fullCatalog, $bytesPerToken);
        $optimizedMeasure = $this->measure($optimizedCatalog, $bytesPerToken);
        $savedBytes = $fullMeasure['bytes'] - $optimizedMeasure['bytes'];

        return [
            'estimation' => [
                'bytesPerToken' => $bytesPerToken,
                'note' => 'Token counts are estimates; JSON tokenization varies by model and host framing.',
            ],
            'budgets' => ['schemaBytesPerTool' => $schemaBudget],
            'schema' => [
                'toolCount' => count($names),
                'armAFull' => $fullMeasure,
                'armBOptimized' => $optimizedMeasure,
                'savings' => [
                    'bytes' => $savedBytes,
                    'estimatedTokens' => $fullMeasure['estimatedTokens'] - $optimizedMeasure['estimatedTokens'],
                    'percent' => $fullMeasure['bytes'] > 0 ? round(($savedBytes / $fullMeasure['bytes']) * 100, 2) : 0.0,
                ],
            ],
            'tools' => $tools,
            'oversizedSchemas' => $oversized,
        ];
    }

    /** @return array{tool: string, bytes: int, estimatedTokens: int, budgetBytes: int, oversized: bool} */
    public function measureResponse(
        string $tool,
        mixed $response,
        int $responseBudget = 32768,
        float $bytesPerToken = 2.42,
    ): array {
        $measurement = $this->measure($response, $bytesPerToken);

        return [
            'tool' => $tool,
            ...$measurement,
            'budgetBytes' => $responseBudget,
            'oversized' => $measurement['bytes'] > $responseBudget,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $baseline
     * @return array{optimizedSchemaBytesDelta: int, responseBytesDelta: array<string, int>}
     */
    public function compareWithBaseline(array $current, array $baseline): array
    {
        $currentSchemaBytes = $this->nestedInt($current, ['schema', 'armBOptimized', 'bytes']);
        $baselineSchemaBytes = $this->nestedInt($baseline, ['schema', 'armBOptimized', 'bytes']);
        $currentResponses = $this->responseBytesByTool($current['responses'] ?? []);
        $baselineResponses = $this->responseBytesByTool($baseline['responses'] ?? []);
        $deltas = [];
        foreach (array_values(array_unique([...array_keys($currentResponses), ...array_keys($baselineResponses)])) as $tool) {
            $deltas[$tool] = ($currentResponses[$tool] ?? 0) - ($baselineResponses[$tool] ?? 0);
        }
        ksort($deltas, SORT_STRING);

        return [
            'optimizedSchemaBytesDelta' => $currentSchemaBytes - $baselineSchemaBytes,
            'responseBytesDelta' => $deltas,
        ];
    }

    /** @return array{bytes: int, estimatedTokens: int} */
    private function measure(mixed $value, float $bytesPerToken): array
    {
        if ($bytesPerToken <= 0) {
            throw new \InvalidArgumentException('bytesPerToken must be greater than zero.');
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bytes = strlen($json);

        return [
            'bytes' => $bytes,
            'estimatedTokens' => (int)ceil($bytes / $bytesPerToken),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     */
    private function nestedInt(array $data, array $path): int
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return 0;
            }
            $value = $value[$segment];
        }

        return is_int($value) ? $value : 0;
    }

    /** @return array<string, int> */
    private function responseBytesByTool(mixed $responses): array
    {
        if (!is_array($responses)) {
            return [];
        }
        $result = [];
        foreach ($responses as $response) {
            if (!is_array($response) || !is_string($response['tool'] ?? null) || !is_int($response['bytes'] ?? null)) {
                continue;
            }
            $result[$response['tool']] = $response['bytes'];
        }

        return $result;
    }
}
