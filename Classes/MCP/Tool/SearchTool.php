<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\Record\InlineSearchAttributionService;
use Hn\McpServer\Service\Record\RecordSearchExecutor;
use Hn\McpServer\Service\Record\RecordSearchResultFormatter;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;

/**
 * Tool for searching records across TYPO3 tables using TCA-based searchable fields
 */
final class SearchTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly LanguageService $languageService,
        private readonly RecordSearchExecutor $searchExecutor,
        private readonly InlineSearchAttributionService $inlineAttributionService,
        private readonly RecordSearchResultFormatter $searchResultFormatter,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $schema = [
            'description' => 'Search for records across workspace-capable TYPO3 tables using TCA-based searchable fields. ' .
                'Uses SQL LIKE queries for pattern matching. Useful when you need to find pages or content that might not be visible in the page tree, ' .
                'or for thorough duplicate checking after initial exploration. ' .
                'Pass either "query" (single string or array of strings) or "terms" (array of strings) — both are accepted.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'description' => 'Search query. Either a single term or an array of terms (synonyms, variants). Equivalent to "terms".',
                    ],
                    'terms' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Alias for "query". Kept for backwards compatibility. Use multiple search terms with synonyms for best results.',
                    ],
                    'termLogic' => [
                        'type' => 'string',
                        'enum' => ['AND', 'OR'],
                        'description' => 'Logic for combining multiple terms: AND (all terms must match) or OR (any term matches). Default: OR',
                        'default' => 'OR',
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: Limit search to a specific workspace-capable table (e.g., "tt_content", "pages")',
                    ],
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Optional: Limit search to records on a specific page',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of records to return per table (default: 50)',
                    ],
                ],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to filter search results (e.g., "de", "fr"). When specified, searches only in content for that language.',
                'enum' => $availableLanguages,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'idempotentHint' => true,
        ];

        return $schema;
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        // Accept "query" as an alias for "terms". A string query is wrapped in a list.
        if (!isset($params['terms']) && isset($params['query'])) {
            $query = $params['query'];
            if (is_string($query)) {
                $params['terms'] = [$query];
            } elseif (is_array($query)) {
                $params['terms'] = $query;
            }
        }

        // Validate all parameters first
        $this->validateParameters($params);

        // Extract parameters
        $terms = $params['terms'] ?? [];
        $termLogic = strtoupper($params['termLogic'] ?? 'OR');
        $table = trim($params['table'] ?? '');
        $pageId = isset($params['pageId']) ? (int)$params['pageId'] : null;
        $limit = 50;

        // Handle language parameter
        $languageId = null;
        if (isset($params['language'])) {
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new ValidationException(['Unknown language code: ' . $params['language']]);
            }
        }

        // Get normalized search terms
        $searchTerms = $this->validateAndNormalizeSearchTerms($terms);

        // Get search results
        $searchResults = $this->performSearch($searchTerms, $termLogic, $table, $pageId, $limit, $languageId);

        // Format results
        $formattedResults = $this->searchResultFormatter->formatSearchResults($searchResults, $searchTerms, $termLogic, $languageId);

        return $this->createSuccessResult($formattedResults);
    }

    /**
     * Validate all parameters
     */
    protected function validateParameters(array $params): void
    {
        $errors = [];

        // Validate terms — accept a list of strings. The "query" alias is normalized into "terms" earlier.
        if (!isset($params['terms']) || !is_array($params['terms'])) {
            $errors[] = 'Provide either "query" (string or array of strings) or "terms" (array of strings).';
        } elseif (empty($params['terms'])) {
            $errors[] = 'At least one search term is required.';
        }

        // Validate term logic
        if (isset($params['termLogic'])) {
            $termLogic = strtoupper($params['termLogic']);
            if (!in_array($termLogic, ['AND', 'OR'])) {
                $errors[] = 'termLogic must be either "AND" or "OR"';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Validate and normalize search terms
     */
    protected function validateAndNormalizeSearchTerms(array $terms): array
    {
        $searchTerms = [];
        $errors = [];

        foreach ($terms as $term) {
            if (!is_string($term)) {
                $errors[] = 'All terms must be strings';
                continue;
            }
            $trimmedTerm = trim($term);
            if (!empty($trimmedTerm)) {
                $searchTerms[] = $trimmedTerm;
            }
        }

        // Validate we have at least one term
        if (empty($searchTerms)) {
            $errors[] = 'At least one non-empty search term is required';
        }

        // Validate term lengths
        foreach ($searchTerms as $term) {
            if (strlen($term) < 2) {
                $errors[] = 'All search terms must be at least 2 characters long. Term "' . $term . '" is too short';
            }
            if (strlen($term) > 100) {
                $errors[] = 'Search terms cannot exceed 100 characters. Term "' . substr($term, 0, 20) . '..." is too long';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $searchTerms;
    }

    /**
     * Perform search across tables (including inline relations)
     */
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
            if (!is_string($tableName)) {
                continue;
            }
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
}
