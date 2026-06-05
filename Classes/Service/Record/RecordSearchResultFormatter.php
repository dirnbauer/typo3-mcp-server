<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Utility\RecordFormattingUtility;

final readonly class RecordSearchResultFormatter
{
    public function __construct(
        private LanguageService $languageService,
    ) {}
    public function formatSearchResults(array $searchResults, array $searchTerms, string $termLogic, ?int $languageId = null): string
    {

        $result = "SEARCH RESULTS\n";
        $result .= "==============\n";

        // Display search terms
        if (count($searchTerms) === 1) {
            $result .= 'Query: "' . $searchTerms[0] . "\"\n";
        } else {
            $result .= 'Search Terms: [' . implode(', ', array_map(fn($t) => '"' . $t . '"', $searchTerms)) . "]\n";
            $result .= 'Logic: ' . $termLogic . ' (records must match ' .
                      ($termLogic === 'AND' ? 'ALL terms' : 'ANY term') . ")\n";
        }

        if ($languageId !== null) {
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown';
            $result .= 'Language Filter: ' . strtoupper($isoCode) . " (ID: $languageId)\n";
        }

        $result .= "\n";

        $totalResults = 0;
        foreach ($searchResults as $tableResults) {
            if (is_array($tableResults) && isset($tableResults['records'])) {
                $totalResults += count($tableResults['records']);
            } else {
                $totalResults += count($tableResults);
            }
        }

        $result .= "Total Results: $totalResults\n";
        $result .= 'Tables Searched: ' . count($searchResults) . "\n\n";

        // If no results, show no results message
        if ($totalResults === 0) {
            $termsDisplay = count($searchTerms) === 1 ? '"' . $searchTerms[0] . '"' : '[' . implode(', ', array_map(fn($t) => '"' . $t . '"', $searchTerms)) . ']';
            $result .= "No results found for search terms: $termsDisplay\n";
        } else {
            // Format results by table
            foreach ($searchResults as $table => $records) {
                $result .= $this->formatTableResults($table, $records, $searchTerms, $languageId);
            }
        }

        return $result;
    }

    public function formatTableResults(string $table, array $tableData, array $searchTerms, ?int $languageId = null): string
    {
        $tableLabel = RecordFormattingUtility::getTableLabel($table);
        $result = "TABLE: $tableLabel ($table)\n";
        $result .= str_repeat('-', strlen("TABLE: $tableLabel ($table)")) . "\n";

        // Handle both searchInTable result structure and attributed results array
        $records = [];
        if (isset($tableData['records'])) {
            // This is a searchInTable result structure
            $records = $tableData['records'];
        } elseif (is_array($tableData) && !empty($tableData)) {
            // This is a direct array of records (from attributed results)
            $records = $tableData;
        }

        $result .= 'Found ' . count($records) . " record(s)\n\n";

        foreach ($records as $record) {
            $result .= $this->formatRecord($table, $record, $searchTerms, $languageId);
        }

        $result .= "\n";
        return $result;
    }

    public function formatRecord(string $table, array $record, array $searchTerms, ?int $languageId = null): string
    {
        $title = RecordFormattingUtility::getRecordTitle($table, $record);
        $uid = $record['uid'] ?? 'unknown';

        $result = "• [UID: $uid] $title\n";

        // Add page information if available
        if (isset($record['_page'])) {
            $pageInfo = $record['_page'];
            $pageTitle = $pageInfo['title'] ?? 'Untitled Page';
            $pageUid = $pageInfo['uid'] ?? 'unknown';
            $result .= "  📍 Page: $pageTitle [UID: $pageUid]\n";
        }

        // Add record type information
        if ($table === 'tt_content' && isset($record['CType'])) {
            $cType = $record['CType'];
            $cTypeLabel = RecordFormattingUtility::getContentTypeLabel($cType);
            $result .= "  🎯 Type: $cTypeLabel ($cType)\n";
        }

        // Add language information if table has language support
        if ($this->tableHasLanguageSupport($table) && isset($record['sys_language_uid'])) {
            $recordLangId = (int)$record['sys_language_uid'];
            if ($recordLangId > 0) {
                $langCode = $this->languageService->getIsoCodeFromUid($recordLangId) ?? 'unknown';
                $result .= '  🌐 Language: ' . strtoupper($langCode) . "\n";
            } elseif ($recordLangId === -1) {
                $result .= "  🌐 Language: All\n";
            }
        }

        // Show preview of matching content
        $preview = $this->getMatchingContentPreview($table, $record, $searchTerms);
        if (!empty($preview)) {
            $result .= "  💬 Preview: $preview\n";
        }

        // Show inline matches if any
        if (isset($record['_inline_matches'])) {
            foreach ($record['_inline_matches'] as $inlineMatch) {
                $inlineTable = $inlineMatch['table'];
                $inlineRecord = $inlineMatch['record'];
                $inlineField = $inlineMatch['field'];
                $inlineType = $inlineMatch['type'];

                $inlineTitle = RecordFormattingUtility::getRecordTitle($inlineTable, $inlineRecord);
                $inlineTableLabel = RecordFormattingUtility::getTableLabel($inlineTable);

                // Show different icons based on relation type
                $icon = $inlineType === 'select' ? '🏷️' : '📎';

                $result .= "  $icon Contains: $inlineTitle [$inlineTableLabel via $inlineField]\n";

                // Show preview of the inline match
                $inlinePreview = $this->getMatchingContentPreview($inlineTable, $inlineRecord, $searchTerms);
                if (!empty($inlinePreview)) {
                    $result .= "    💬 Match: $inlinePreview\n";
                }
            }
        }

        $result .= "\n";
        return $result;
    }

    public function getMatchingContentPreview(string $table, array $record, array $searchTerms): string
    {
        $searchableFields = $this->getSearchableFields($table);
        $previews = [];

        foreach ($searchableFields as $field) {
            if (!isset($record[$field]) || empty($record[$field])) {
                continue;
            }

            $content = (string)$record[$field];

            // Remove HTML tags for preview
            $content = strip_tags($content);

            // Check if this field contains any of the search terms
            foreach ($searchTerms as $term) {
                if (stripos($content, (string)$term) !== false) {
                    // Extract a snippet around the match
                    $snippet = RecordFormattingUtility::extractSnippet($content, $term);
                    if (!empty($snippet)) {
                        $previews[] = $snippet;
                        break; // Found a match in this field, move to next field
                    }
                }
            }
        }

        if (empty($previews)) {
            return '';
        }

        return implode(' ... ', array_slice($previews, 0, 2)); // Limit to 2 snippets
    }

}
