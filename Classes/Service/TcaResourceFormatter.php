<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Utility\TcaFormattingUtility;
use Mcp\Types\TextContent;

/**
 * Formats TCA data for MCP resources and dev-site reference output.
 */
final readonly class TcaResourceFormatter
{
    public function __construct(
        private TableAccessService $tableAccessService,
        private GetTableSchemaTool $getTableSchemaTool,
    ) {}

    public function renderOverview(): string
    {
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        ksort($accessibleTables);

        $output = "# TYPO3 TCA (Table Configuration Array) Overview\n\n";
        $output .= 'Total accessible tables: ' . count($accessibleTables) . "\n\n";
        $output .= "## Available Tables\n\n";

        foreach ($accessibleTables as $tableName => $info) {
            $label = TableAccessService::translateLabel($info['title'] ?? $tableName);
            $tca = $GLOBALS['TCA'] ?? [];
            $tableTca = is_array($tca) && is_array($tca[$tableName] ?? null) ? $tca[$tableName] : [];
            $ctrl = is_array($tableTca['ctrl'] ?? null) ? $tableTca['ctrl'] : [];
            $labelField = $ctrl['label'] ?? 'N/A';
            $readOnly = ($info['read_only'] ?? false) ? ' (read-only)' : '';
            $output .= sprintf(
                "- **%s** (`%s`) — label field: `%s`%s\n",
                $label,
                $tableName,
                is_string($labelField) ? $labelField : 'N/A',
                $readOnly,
            );
        }

        $output .= "\n## Usage\n\n";
        $output .= "Read a specific table with MCP resource URI `typo3-mcp:///tca/{tableName}`.\n";
        $output .= "Example: `typo3-mcp:///tca/pages` or `typo3-mcp:///tca/tt_content`\n";

        return $output;
    }

    public function renderTable(string $tableName): string
    {
        if (!$this->tableAccessService->canAccessTable($tableName)) {
            return "# Access denied\n\nTable `{$tableName}` is not accessible to the current backend user.\n";
        }

        if (!is_array($GLOBALS['TCA'] ?? null) || !is_array($GLOBALS['TCA'][$tableName] ?? null)) {
            return "# Error\n\nTable `{$tableName}` not found in TCA.\n\nUse `typo3-mcp:///tca` to see accessible tables.";
        }

        $result = $this->getTableSchemaTool->execute(['table' => $tableName]);
        $schemaText = '';
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                $schemaText = $content->text;
                break;
            }
        }

        $output = "# TCA Configuration: {$tableName}\n\n";
        $output .= $schemaText;
        $output .= "\n\n## Raw field summary\n\n";

        /** @var array<string, mixed> $tca */
        $tca = $GLOBALS['TCA'][$tableName];
        $columns = is_array($tca['columns'] ?? null) ? $tca['columns'] : [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (!is_string($fieldName) || !is_array($fieldConfig)) {
                continue;
            }
            if (!$this->tableAccessService->canAccessField($tableName, $fieldName)) {
                continue;
            }
            $output .= '- `' . $fieldName . '`';
            /** @var array<string, mixed> $fieldConfig */
            TcaFormattingUtility::addFieldDetailsInline($output, $fieldConfig, $fieldName, $tableName);
            $output .= "\n";
        }

        return $output;
    }
}
