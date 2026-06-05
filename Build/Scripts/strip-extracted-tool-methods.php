<?php

declare(strict_types=1);

function removeLineRanges(string $file, array $ranges): void
{
    $lines = file($file);
    $remove = [];
    foreach ($ranges as [$start, $end]) {
        for ($i = $start; $i <= $end; $i++) {
            $remove[$i] = true;
        }
    }
    $out = '';
    foreach ($lines as $idx => $line) {
        if (!isset($remove[$idx + 1])) {
            $out .= $line;
        }
    }
    file_put_contents($file, $out);
}

$read = __DIR__ . '/../../Classes/MCP/Tool/Record/ReadTableTool.php';
$search = __DIR__ . '/../../Classes/MCP/Tool/SearchTool.php';

removeLineRanges($read, [
    [112, 123],
    [335, 530],
    [532, 758],
    [760, 1022],
    [1024, 1414],
    [1416, 1438],
    [1504, 1544],
]);

removeLineRanges($search, [
    [107, 204],
    [367, 632],
    [634, 847],
    [849, 1035],
]);

echo "Stripped extracted methods from tools\n";
