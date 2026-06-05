<?php

declare(strict_types=1);

/**
 * Remove named class methods from a PHP file (1-based line numbers in errors).
 *
 * @param list<string> $methodNames
 */
function stripMethods(string $file, array $methodNames): void
{
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }

    $tokens = token_get_all($source);
    $removeRanges = [];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue;
        }

        $name = $tokens[$j][1];
        if (!in_array($name, $methodNames, true)) {
            continue;
        }

        // Walk back to include docblock / attributes
        $start = $i;
        while ($start > 0) {
            $prev = $start - 1;
            if (!is_array($tokens[$prev])) {
                break;
            }
            if (in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($tokens[$prev][0] === T_DOC_COMMENT) {
                    $start = $prev;
                } else {
                    $start = $prev;
                }
                continue;
            }
            if (in_array($tokens[$prev][0], [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                $start = $prev;
                continue;
            }
            break;
        }

        // Do not include blank lines that belong to the previous method (before the docblock).
        while ($start < $i && is_array($tokens[$start]) && $tokens[$start][0] === T_WHITESPACE) {
            $start++;
        }

        // Find opening brace of function body
        while ($j < $count && $tokens[$j] !== '{') {
            $j++;
        }
        if ($j >= $count) {
            continue;
        }

        $depth = 0;
        $end = $j;
        for (; $end < $count; $end++) {
            if ($tokens[$end] === '{') {
                $depth++;
            } elseif ($tokens[$end] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $startLine = null;
        for ($k = $start; $k <= $end; $k++) {
            if (is_array($tokens[$k]) && isset($tokens[$k][2])) {
                $startLine = $tokens[$k][2];
                break;
            }
        }
        $endLine = null;
        for ($k = $start; $k <= $end; $k++) {
            if (is_array($tokens[$k]) && isset($tokens[$k][2])) {
                $endLine = max($endLine ?? 0, $tokens[$k][2]);
            }
        }

        if ($startLine === null || $endLine === null) {
            continue;
        }

        // Include opening docblock lines when the tokenizer starts mid-comment.
        $sourceLines = explode("\n", $source);
        while ($startLine > 1 && preg_match('#^\s*(\/\*\*|\*|\*\/)\s*$#', $sourceLines[$startLine - 2] ?? '') === 1) {
            $startLine--;
        }

        // Include the method's closing brace line when it sits after the last tokenized line.
        if ($endLine !== null && $endLine < count($sourceLines) && trim($sourceLines[$endLine] ?? '') === '}') {
            $endLine++;
        }

        $removeRanges[] = [$startLine, $endLine];
        $i = $end;
    }

    if ($removeRanges === []) {
        return;
    }

    usort($removeRanges, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

    $lines = explode("\n", $source);
    foreach ($removeRanges as [$startLine, $endLine]) {
        for ($line = $startLine; $line <= $endLine; $line++) {
            unset($lines[$line - 1]);
        }
    }

    file_put_contents($file, implode("\n", $lines));
}

$readTool = __DIR__ . '/../../Classes/MCP/Tool/Record/ReadTableTool.php';
$searchTool = __DIR__ . '/../../Classes/MCP/Tool/SearchTool.php';

$writeTool = __DIR__ . '/../../Classes/MCP/Tool/Record/WriteTableTool.php';

stripMethods($readTool, [
    'getTableCtrlArray',
    'normalizeSystemFieldFilters',
    'normalizeFieldNames',
    'getRecords',
    'applyFilters',
    'isIntegerArray',
    'applyDefaultSorting',
    'includeRelations',
    'includeSelectRelations',
    'includeMmRelations',
    'includeRegularRelations',
    'includeStaticItems',
    'includeInlineRelations',
    'getInlineRelatedRecords',
    'getMmRelationValues',
    'applyWorkspaceOverlay',
    'processRecord',
    'convertFieldValue',
    'tableHasPidField',
]);

stripMethods($writeTool, [
    'getTableCtrlArray',
    'getTableTcaArray',
    'getTableColumnsArray',
    'extractInlineRelations',
    'buildInlineDataMap',
    'syncInlineRelations',
    'validateInlineRelationData',
    'convertDataForStorage',
]);

stripMethods($searchTool, [
    'removedExamples',
    'getLanguageRecommendations',
    'attributeInlineResultsToParents',
    'findParentRecordsForInlineRecord',
    'getTablesToSearch',
    'getInlineRelatedHiddenTables',
    'getSearchableFields',
    'validateSearchableFields',
    'searchInTable',
    'tableHasLanguageSupport',
    'enhanceRecordsWithPageInfo',
    'getPageInfo',
    'formatSearchResults',
    'formatTableResults',
    'formatRecord',
    'getMatchingContentPreview',
    'tableHasPidField',
]);

echo "Stripped methods by name\n";
