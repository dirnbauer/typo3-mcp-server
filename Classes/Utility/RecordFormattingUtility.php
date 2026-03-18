<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility class for formatting TYPO3 records consistently across MCP tools
 *
 * @phpstan-type RecordRow array<string, mixed>
 */
final class RecordFormattingUtility
{
    /**
     * @return array<string, mixed>
     */
    protected static function getTableCtrl(string $table): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return [];
        }

        $ctrl = $tableConfig['ctrl'] ?? null;
        return \is_array($ctrl) ? $ctrl : [];
    }

    /**
     * @return list<mixed>
     */
    protected static function getSelectItems(string $table, string $fieldName): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return [];
        }

        $columns = $tableConfig['columns'] ?? null;
        if (!\is_array($columns)) {
            return [];
        }

        $fieldConfig = $columns[$fieldName] ?? null;
        if (!\is_array($fieldConfig)) {
            return [];
        }

        $config = $fieldConfig['config'] ?? null;
        if (!\is_array($config)) {
            return [];
        }

        $items = $config['items'] ?? null;
        return \is_array($items) ? array_values($items) : [];
    }

    protected static function getDefaultPageTsconfig(): string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!\is_array($configuration)) {
            return '';
        }

        $beConfig = $configuration['BE'] ?? null;
        if (!\is_array($beConfig)) {
            return '';
        }

        return \is_string($beConfig['defaultPageTSconfig'] ?? null) ? $beConfig['defaultPageTSconfig'] : '';
    }
    /**
     * Get table label from TCA
     */
    public static function getTableLabel(string $table): string
    {
        $title = self::getTableCtrl($table)['title'] ?? null;
        if (\is_string($title) && $title !== '') {
            return TableAccessService::translateLabel($title);
        }

        // Fallback to humanized table name
        return ucfirst(str_replace(['tx_', '_'], ['', ' '], $table));
    }

    /**
     * Get a meaningful title for a record
     *
     * @param RecordRow $record
     */
    public static function getRecordTitle(string $table, array $record): string
    {
        // Try to use BackendUtility to get a proper record title
        try {
            $title = BackendUtility::getRecordTitle($table, $record);
            if (!empty($title)) {
                return $title;
            }
        } catch (\Throwable) {
            // Fall back to manual title detection
        }

        // Use the TCA label field if defined
        $labelField = self::getTableCtrl($table)['label'] ?? null;
        if (\is_string($labelField) && !empty($record[$labelField]) && \is_scalar($record[$labelField])) {
            return (string)$record[$labelField];
        }

        // Common title fields in TYPO3
        $titleFields = ['title', 'header', 'name', 'username', 'first_name', 'lastname', 'subject'];

        foreach ($titleFields as $field) {
            if (!empty($record[$field])) {
                return \is_scalar($record[$field]) ? (string)$record[$field] : 'Record';
            }
        }

        // Last resort, just return the UID
        $recordUid = is_numeric($record['uid'] ?? null) ? (int)$record['uid'] : 0;
        return 'Record #' . $recordUid;
    }

    /**
     * Get a label for a content type
     */
    public static function getContentTypeLabel(string $cType): string
    {
        foreach (self::getSelectItems('tt_content', 'CType') as $item) {
            if (\is_array($item) && isset($item['value']) && $item['value'] === $cType && \is_scalar($item['label'] ?? null)) {
                return TableAccessService::translateLabel((string)$item['label']);
            }
            if (\is_array($item) && isset($item[1]) && $item[1] === $cType && \is_scalar($item[0] ?? null)) {
                return TableAccessService::translateLabel((string)$item[0]);
            }
        }

        // Fallback to a humanized version of the CType
        return ucfirst(str_replace('_', ' ', $cType));
    }

    /**
     * Get column position definitions
     *
     * @param int|null $pageId The page ID to get backend layout for (optional)
     * @param bool &$hasCustomLayout Output parameter to indicate if a custom layout is in use
     * @return array<int, string>
     */
    public static function getColumnPositionDefinitions(?int $pageId = null, bool &$hasCustomLayout = false): array
    {
        // Default column positions
        $colPosDefs = [
            0 => 'Main Content',
            1 => 'Left',
            2 => 'Right',
            3 => 'Border',
            4 => 'Footer',
        ];

        $hasCustomLayout = false;

        // Try to get columns from backend layout if page ID is provided
        if ($pageId !== null) {
            try {
                $backendLayout = self::getBackendLayoutForPage($pageId);
                $backendLayoutConfig = \is_array($backendLayout['__config'] ?? null) ? $backendLayout['__config'] : [];
                $backendLayoutSection = \is_array($backendLayoutConfig['backend_layout.'] ?? null) ? $backendLayoutConfig['backend_layout.'] : [];
                $rows = \is_array($backendLayoutSection['rows.'] ?? null) ? $backendLayoutSection['rows.'] : [];
                if ($rows !== []) {
                    $layoutColumns = self::extractColumnsFromBackendLayout($rows);
                    if (!empty($layoutColumns)) {
                        $hasCustomLayout = true;
                        return $layoutColumns;
                    }
                }
            } catch (\Exception) {
                // Fall back to defaults on error
            }
        }

        // Try to get column positions from page TSconfig
        $tsconfigString = self::getDefaultPageTsconfig();
        if ($tsconfigString !== '' && preg_match_all('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.tt_content_defValues\.colPos\s*=\s*(\d+)/', $tsconfigString, $matches)) {
            foreach ($matches[1] as $colPos) {
                $colPosInt = (int)$colPos;
                if (!isset($colPosDefs[$colPosInt])) {
                    // Try to find the label for this column position
                    if (preg_match('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.title\s*=\s*(.+)/', $tsconfigString, $labelMatches)) {
                        $colPosDefs[$colPosInt] = $labelMatches[1];
                    } else {
                        $colPosDefs[$colPosInt] = 'Column ' . $colPosInt;
                    }
                }
            }
        }

        // Check for backend layouts
        foreach (self::getSelectItems('backend_layout', 'config') as $item) {
            if (\is_array($item) && isset($item[1]) && \is_scalar($item[1]) && preg_match('/colPos=(\d+)/', (string)$item[1], $matches)) {
                $colPos = (int)$matches[1];
                if (!isset($colPosDefs[$colPos]) && \is_scalar($item[0] ?? null)) {
                    $colPosDefs[$colPos] = TableAccessService::translateLabel((string)$item[0]);
                }
            }
        }

        return $colPosDefs;
    }

    /**
     * Check if a table has a pid field
     */
    public static function tableHasPidField(string $table): bool
    {
        // System tables that don't have pid
        $tablesWithoutPid = [
            'be_users', 'be_groups', 'sys_registry', 'sys_log', 'sys_history',
            'sys_file', 'be_sessions', 'fe_sessions', 'sys_domain',
        ];

        return !\in_array($table, $tablesWithoutPid);
    }

    /**
     * Apply default sorting from TCA to a query builder
     */
    public static function applyDefaultSorting(QueryBuilder $queryBuilder, string $table): void
    {
        $ctrl = self::getTableCtrl($table);
        if ($ctrl === []) {
            return;
        }

        // Check for sortby field
        $sortby = \is_string($ctrl['sortby'] ?? null) ? $ctrl['sortby'] : '';
        if ($sortby !== '') {
            $queryBuilder->orderBy($sortby, 'ASC');
            return;
        }

        // Check for default_sortby
        $defaultSortby = \is_string($ctrl['default_sortby'] ?? null) ? $ctrl['default_sortby'] : '';
        if ($defaultSortby !== '') {
            $sortParts = GeneralUtility::trimExplode(',', str_replace('ORDER BY', '', $defaultSortby), true);
            foreach ($sortParts as $sortPart) {
                $sortPart = trim($sortPart);
                if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $sortPart, $matches)) {
                    $field = trim($matches[1]);
                    $direction = strtoupper($matches[2]);
                    $queryBuilder->addOrderBy($field, $direction);
                } else {
                    $queryBuilder->addOrderBy($sortPart, 'ASC');
                }
            }
            return;
        }

        // Default to ordering by UID
        $queryBuilder->orderBy('uid', 'ASC');
    }

    /**
     * Format content preview for display
     */
    public static function formatContentPreview(string $content, int $maxLength = 100): string
    {
        if (empty($content)) {
            return '';
        }

        // Remove HTML tags
        $content = strip_tags($content);

        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;
        $content = trim($content);

        // Truncate if too long
        if (mb_strlen($content) > $maxLength) {
            $content = mb_substr($content, 0, $maxLength) . '...';
        }

        return $content;
    }

    /**
     * Extract a snippet around a search query
     */
    public static function extractSnippet(string $content, string $query, int $contextLength = 50): string
    {
        $pos = stripos($content, $query);
        if ($pos === false) {
            return '';
        }

        $start = max(0, $pos - $contextLength);
        $length = $contextLength * 2 + \strlen($query);

        $snippet = substr($content, $start, $length);

        // Add ellipsis if needed
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if ($start + $length < \strlen($content)) {
            $snippet .= '...';
        }

        // Highlight the query (simple text-based highlighting)
        $snippet = str_ireplace($query, "**$query**", $snippet);

        return trim($snippet);
    }

    /**
     * Get backend layout for a specific page
     *
     * @param int $pageId
     * @return array<string, mixed>|null
     */
    protected static function getBackendLayoutForPage(int $pageId): ?array
    {
        try {
            // Get page record to check for backend layout settings
            $pageRecord = BackendUtility::getRecord('pages', $pageId);
            if (!$pageRecord) {
                return null;
            }

            // First, try the simpler approach: check if backend_layout is set directly
            $backendLayoutIdentifier = \is_scalar($pageRecord['backend_layout'] ?? null) ? (string)$pageRecord['backend_layout'] : '';

            // If not set on this page, check parent pages for backend_layout_next_level
            $parentPid = is_numeric($pageRecord['pid'] ?? null) ? (int)$pageRecord['pid'] : 0;
            if ($backendLayoutIdentifier === '' && $parentPid > 0) {
                $backendLayoutIdentifier = self::getInheritedBackendLayout($parentPid);
            }

            if (!empty($backendLayoutIdentifier)) {
                // Check if it's a numeric ID (database record)
                if (is_numeric($backendLayoutIdentifier)) {
                    $layoutRecord = BackendUtility::getRecord('backend_layout', (int)$backendLayoutIdentifier);
                    if ($layoutRecord && \is_string($layoutRecord['config'] ?? null) && $layoutRecord['config'] !== '') {
                        // Parse the backend layout config directly
                        $config = self::parseBackendLayoutConfig($layoutRecord['config']);
                        if ($config !== []) {
                            return ['__config' => $config];
                        }
                    }
                } else {
                    // It's a string identifier, might be TSConfig-based
                    // Try to get TSConfig backend layout
                    $pageTsConfig = BackendUtility::getPagesTSconfig($pageId);
                    if (isset($pageTsConfig['mod.']['web_layout.']['BackendLayouts.'][$backendLayoutIdentifier . '.'])) {
                        $layoutConfig = $pageTsConfig['mod.']['web_layout.']['BackendLayouts.'][$backendLayoutIdentifier . '.'];
                        if (isset($layoutConfig['config.'])) {
                            return ['__config' => $layoutConfig['config.']];
                        }
                    }
                }
            }

            // Fallback: try using BackendLayoutView (but this often requires full backend context)
            try {
                $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);
                $backendLayout = $backendLayoutView->getBackendLayoutForPage($pageId);

                if ($backendLayout) {
                    return $backendLayout->getStructure();
                }
            } catch (\Throwable $e) {
                // BackendLayoutView might not work in all contexts
            }
        } catch (\Throwable $e) {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning('Backend layout detection failed', ['exception' => $e]);
        }

        return null;
    }

    /**
     * Get inherited backend layout from parent pages
     *
     * @param int $parentId
     * @return string
     */
    protected static function getInheritedBackendLayout(int $parentId): string
    {
        $parentRecord = BackendUtility::getRecord('pages', $parentId, 'pid,backend_layout_next_level');

        if ($parentRecord) {
            // Check if parent has backend_layout_next_level set
            if (!empty($parentRecord['backend_layout_next_level']) && \is_scalar($parentRecord['backend_layout_next_level'])) {
                return (string)$parentRecord['backend_layout_next_level'];
            }

            // If parent has a parent, check recursively
            $parentPid = is_numeric($parentRecord['pid'] ?? null) ? (int)$parentRecord['pid'] : 0;
            if ($parentPid > 0) {
                return self::getInheritedBackendLayout($parentPid);
            }
        }

        return '';
    }

    /**
     * Extract column definitions from backend layout configuration
     *
     * @param array<int|string, mixed> $rows
     * @return array<int, string>
     */
    protected static function extractColumnsFromBackendLayout(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['columns.']) || !\is_array($row['columns.'])) {
                continue;
            }

            foreach ($row['columns.'] as $column) {
                if (!\is_array($column) || !isset($column['colPos'])) {
                    continue;
                }

                $colPos = is_numeric($column['colPos']) ? (int)$column['colPos'] : 0;
                $name = \is_scalar($column['name'] ?? null) ? (string)$column['name'] : 'Column ' . $colPos;

                // Translate the name if it's a language label
                if (str_starts_with($name, 'LLL:')) {
                    $name = TableAccessService::translateLabel($name);
                }

                $columns[$colPos] = $name;
            }
        }

        return $columns;
    }

    /**
     * Parse backend layout configuration from TypoScript-like format
     *
     * @param string $config
     * @return array<string, mixed>
     */
    protected static function parseBackendLayoutConfig(string $config): array
    {
        // Simple parser for backend layout config
        // We're looking for rows.X.columns.Y structure
        $lines = explode("\n", $config);
        $result = [];
        $currentPath = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Handle closing brace
            if ($line === '}') {
                array_pop($currentPath);
                continue;
            }

            // Handle property with opening brace
            if (preg_match('/^(\w+)\s*\{/', $line, $matches)) {
                $currentPath[] = $matches[1] . '.';
                continue;
            }

            // Handle simple assignment
            if (preg_match('/^(\w+)\s*=\s*(.*)/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);

                // Build the full path
                $fullPath = implode('', $currentPath) . $key;

                self::assignNestedValue($result, explode('.', $fullPath), $value);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $pathParts
     */
    protected static function assignNestedValue(array &$result, array $pathParts, string $value): void
    {
        $filteredParts = array_values(array_filter($pathParts, static fn(string $part): bool => $part !== ''));
        if ($filteredParts === []) {
            return;
        }

        $current = &$result;
        $lastIndex = \count($filteredParts) - 1;
        foreach ($filteredParts as $index => $part) {
            if ($index === $lastIndex) {
                $current[$part] = $value;
                return;
            }

            $key = $part . '.';
            if (!\array_key_exists($key, $current) || !\is_array($current[$key])) {
                $current[$key] = [];
            }
            /** @var array<string, mixed> $next */
            $next = &$current[$key];
            $current = &$next;
        }
    }
}
