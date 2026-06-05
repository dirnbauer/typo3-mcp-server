<?php

declare(strict_types=1);

$readTool = __DIR__ . '/../../Classes/MCP/Tool/Record/ReadTableTool.php';
$searchTool = __DIR__ . '/../../Classes/MCP/Tool/SearchTool.php';

$read = file_get_contents($readTool);
$search = file_get_contents($searchTool);

$read = str_replace(
    "use Hn\\McpServer\\Service\\LanguageService;\nuse Hn\\McpServer\\Service\\TableAccessService;",
    "use Hn\\McpServer\\Service\\LanguageService;\nuse Hn\\McpServer\\Service\\Record\\RecordFieldReadConverter;\nuse Hn\\McpServer\\Service\\Record\\RecordReadQueryService;\nuse Hn\\McpServer\\Service\\Record\\RecordRelationReadService;\nuse Hn\\McpServer\\Service\\TableAccessService;",
    $read,
);
$read = str_replace(
    "        protected readonly LanguageService \$languageService,\n        private readonly ConnectionPool \$connectionPool,\n    ) {",
    "        protected readonly LanguageService \$languageService,\n        private readonly ConnectionPool \$connectionPool,\n        private readonly RecordReadQueryService \$readQueryService,\n        private readonly RecordRelationReadService \$relationReadService,\n        private readonly RecordFieldReadConverter \$fieldReadConverter,\n    ) {",
    $read,
);
$read = str_replace('$this->normalizeFieldNames(', '$this->readQueryService->normalizeFieldNames(', $read);
$read = str_replace('$this->normalizeSystemFieldFilters(', '$this->readQueryService->normalizeSystemFieldFilters(', $read);
$read = str_replace('$this->getRecords(', '$this->readQueryService->getRecords(', $read);
$read = str_replace('$this->includeRelations(', '$this->relationReadService->includeRelations(', $read);
$read = str_replace('$this->convertFieldValue(', '$this->fieldReadConverter->convertFieldValue(', $read);
$read = str_replace('$this->applyWorkspaceOverlay(', '$this->readQueryService->applyWorkspaceOverlay(', $read);
$read = str_replace('$this->processRecord(', '$this->fieldReadConverter->processRecord(', $read);

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

$search = str_replace($performSearchOld, $performSearchNew, $search);

file_put_contents($readTool, $read);
file_put_contents($searchTool, $search);

$writeTool = __DIR__ . '/../../Classes/MCP/Tool/Record/WriteTableTool.php';
$write = file_get_contents($writeTool);
$write = str_replace(
    "use Hn\\McpServer\\Service\\Record\\RecordSearchReplaceService;\nuse Hn\\McpServer\\Service\\TableAccessService;",
    "use Hn\\McpServer\\Service\\Record\\RecordDataWriteConverter;\nuse Hn\\McpServer\\Service\\Record\\RecordInlineRelationWriteService;\nuse Hn\\McpServer\\Service\\Record\\RecordSearchReplaceService;\nuse Hn\\McpServer\\Service\\TableAccessService;\nuse Hn\\McpServer\\Service\\TableTcaResolver;",
    $write,
);
$write = str_replace(
    "        private readonly RecordSearchReplaceService \$searchReplaceService,\n    ) {",
    "        private readonly RecordSearchReplaceService \$searchReplaceService,\n        private readonly RecordDataWriteConverter \$dataWriteConverter,\n        private readonly RecordInlineRelationWriteService \$inlineRelationService,\n        private readonly TableTcaResolver \$tcaResolver,\n    ) {",
    $write,
);
$write = str_replace('$this->extractInlineRelations(', '$this->inlineRelationService->extractFromData(', $write);
$write = str_replace('$this->buildInlineDataMap(', '$this->inlineRelationService->buildDataMap(', $write);
$write = str_replace('$this->syncInlineRelations(', '$this->inlineRelationService->syncRelations(', $write);
$write = str_replace('$this->validateInlineRelationData(', '$this->inlineRelationService->validateField(', $write);
$write = str_replace('$this->convertDataForStorage(', '$this->dataWriteConverter->convert(', $write);
$write = str_replace('$this->getTableCtrlArray(', '$this->tcaResolver->getCtrl(', $write);
$write = str_replace('$this->getTableTcaArray(', '$this->tcaResolver->getTable(', $write);
$write = str_replace('$this->getTableColumnsArray(', '$this->tcaResolver->getColumns(', $write);
file_put_contents($writeTool, $write);

echo "Wired ReadTableTool, SearchTool, and WriteTableTool\n";
