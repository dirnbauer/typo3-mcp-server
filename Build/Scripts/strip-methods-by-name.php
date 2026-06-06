<?php

declare(strict_types=1);

/**
 * @param list<array{0: int, 1: int, 2: string}>|list<string> $tokens
 */
function tokenByteOffset(array $tokens, int $index): int
{
    $token = $tokens[$index];
    if (is_array($token) && isset($token[3])) {
        return (int)$token[3];
    }

    $offset = 0;
    for ($i = 0; $i < $index; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            $offset = isset($t[3]) ? (int)$t[3] + strlen($t[1]) : $offset + strlen($t[1]);
        } else {
            $offset += strlen($t);
        }
    }

    return $offset;
}

function tokenByteLength(array|string $token): int
{
    return is_array($token) ? strlen($token[1]) : strlen($token);
}

function lineNumberAtOffset(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

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
                // Ignore `}` that closes "{$var}" interpolation inside double-quoted strings.
                $prev = $end - 1;
                while ($prev > $j && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev > $j && (
                    (is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_VARIABLE, T_STRING_VARNAME], true))
                    || $tokens[$prev] === ']'
                    || $tokens[$prev] === ')'
                )) {
                    continue;
                }

                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $startOffset = tokenByteOffset($tokens, $start);
        $startLine = lineNumberAtOffset($source, $startOffset);

        $endOffset = tokenByteOffset($tokens, $end) + tokenByteLength($tokens[$end]);
        $endLine = lineNumberAtOffset($source, $endOffset);

        // Include opening docblock lines when the tokenizer starts mid-comment.
        $sourceLines = explode("\n", $source);
        while ($startLine > 1 && preg_match('#^\s*(\/\*\*|\*|\*\/)\s*$#', $sourceLines[$startLine - 2] ?? '') === 1) {
            $startLine--;
        }

        $removeRanges[] = [$startLine, $endLine];
        $i = $end;
    }

    if ($removeRanges === []) {
        return;
    }

    usort($removeRanges, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

    $lines = explode("\n", $source);
    $linesToRemove = [];
    foreach ($removeRanges as [$startLine, $endLine]) {
        for ($line = $startLine; $line <= $endLine; $line++) {
            $linesToRemove[$line] = true;
        }
    }

    $out = [];
    foreach ($lines as $idx => $line) {
        if (!isset($linesToRemove[$idx + 1])) {
            $out[] = $line;
        }
    }

    file_put_contents($file, implode("\n", $out));
}

$readTool = __DIR__ . '/../../Classes/MCP/Tool/Record/ReadTableTool.php';
$searchTool = __DIR__ . '/../../Classes/MCP/Tool/SearchTool.php';

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

$search = file_get_contents($searchTool);
if ($search === false) {
    throw new RuntimeException('Cannot read ' . $searchTool);
}
$search = str_replace(
    "use Hn\\McpServer\\Service\\LanguageService;\nuse Hn\\McpServer\\Service\\TableAccessService;",
    "use Hn\\McpServer\\Service\\LanguageService;\nuse Hn\\McpServer\\Service\\Record\\InlineSearchAttributionService;\nuse Hn\\McpServer\\Service\\Record\\RecordSearchExecutor;\nuse Hn\\McpServer\\Service\\Record\\RecordSearchResultFormatter;\nuse Hn\\McpServer\\Service\\TableAccessService;",
    $search,
);
$search = str_replace(
    "        private readonly LanguageService \$languageService,\n        private readonly ConnectionPool \$connectionPool,\n    ) {",
    "        private readonly LanguageService \$languageService,\n        private readonly ConnectionPool \$connectionPool,\n        private readonly RecordSearchExecutor \$searchExecutor,\n        private readonly InlineSearchAttributionService \$inlineAttributionService,\n        private readonly RecordSearchResultFormatter \$searchResultFormatter,\n    ) {",
    $search,
);
$search = str_replace(
    '$this->formatSearchResults(',
    '$this->searchResultFormatter->formatSearchResults(',
    $search,
);
$performSearchOld = <<<'PHP'
    protected function performSearch(array $searchTerms, string $termLogic, string $table, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $searchResults = [];
        $inlineTableMetadata = [];

        // Get primary tables to search (accessible tables with searchable fields)
        $primaryTables = $this->getTablesToSearch($table);

        // Discover related tables referenced by primary tables (inline/select relations)
        $inlineTableInfo = $this->getInlineRelatedHiddenTables($primaryTables);

        // Create lookup for inline table metadata
        foreach ($inlineTableInfo as $inlineInfo) {
            $inlineTableMetadata[$inlineInfo['table']] = $inlineInfo;
        }

        // Combine primary tables with inline tables for searching
        $allTablesToSearch = array_merge($primaryTables, array_column($inlineTableInfo, 'table'));

        foreach ($allTablesToSearch as $tableName) {
            $searchableFields = $this->getSearchableFields($tableName);

            if (empty($searchableFields)) {
                continue;
            }

            $results = $this->searchInTable($tableName, $searchTerms, $termLogic, $searchableFields, $pageId, $limit, $languageId);

            if (!empty($results) && !empty($results['records'])) {
                // Mark inline table results for attribution
                if (isset($inlineTableMetadata[$tableName])) {
                    $results['_inline_metadata'] = $inlineTableMetadata[$tableName];
                }

                $searchResults[$tableName] = $results;
            }
        }

        // Attribute inline results to parent records
        $attributedResults = $this->attributeInlineResultsToParents($searchResults, $inlineTableMetadata);

        return $attributedResults;
    }
PHP;
$performSearchNew = <<<'PHP'
    protected function performSearch(array $searchTerms, string $termLogic, string $table, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $searchResults = [];
        $inlineTableMetadata = [];

        $primaryTables = $this->searchExecutor->getTablesToSearch($table);
        $inlineTableInfo = $this->inlineAttributionService->getInlineRelatedHiddenTables($primaryTables);

        foreach ($inlineTableInfo as $inlineInfo) {
            $inlineTableMetadata[$inlineInfo['table']] = $inlineInfo;
        }

        $allTablesToSearch = array_merge($primaryTables, array_column($inlineTableInfo, 'table'));

        foreach ($allTablesToSearch as $tableName) {
            $searchableFields = $this->searchExecutor->getSearchableFields($tableName);
            if ($searchableFields === []) {
                continue;
            }

            $results = $this->searchExecutor->searchInTable(
                $tableName,
                $searchTerms,
                $termLogic,
                $searchableFields,
                $pageId,
                $limit,
                $languageId,
            );

            if ($results !== [] && ($results['records'] ?? []) !== []) {
                if (isset($inlineTableMetadata[$tableName])) {
                    $results['_inline_metadata'] = $inlineTableMetadata[$tableName];
                }
                $searchResults[$tableName] = $results;
            }
        }

        return $this->inlineAttributionService->attributeInlineResultsToParents($searchResults, $inlineTableMetadata);
    }
PHP;
if (str_contains($search, '$this->getTablesToSearch(')) {
    $search = str_replace($performSearchOld, $performSearchNew, $search);
}
file_put_contents($searchTool, $search);

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

foreach ([$readTool, $searchTool] as $file) {
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        throw new RuntimeException("Syntax error after stripping {$file}:\n" . implode("\n", $lintOutput));
    }
}

echo "Wired SearchTool and stripped methods by name\n";
