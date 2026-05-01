<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Utility\TcaFormattingUtility;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for getting detailed schema information for a specific table
 */
final class GetTableSchemaTool extends AbstractRecordTool
{
    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        // Get all accessible tables for enum
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        $tableNames = array_keys($accessibleTables);
        sort($tableNames);

        return [
            'description' => 'Get detailed schema information for a specific table type, including fields, relations, and validation',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table to get schema information for',
                        'enum' => $tableNames,
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Specific record type to show fields for (e.g., "textmedia" for tt_content). Each type has different fields. ' .
                            'If omitted, shows fields for the first available type and lists all available types. ' .
                            'Call again with a different type to see its fields. Types may be filtered by backend TSconfig (some types hidden from the list).',
                    ],
                ],
                'required' => ['table'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        $table = isset($params['table']) && is_string($params['table']) ? $params['table'] : '';

        if (empty($table)) {
            throw new \InvalidArgumentException('Table parameter is required');
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, 'read');

        $filterType = isset($params['type']) && is_string($params['type']) ? $params['type'] : '';

        $result = $this->generateTableSchema($table, $filterType);
        return $this->createSuccessResult($result);
    }

    /**
     * Generate a table schema as text
     */
    protected function generateTableSchema(string $table, string $filterType = ''): string
    {
        $result = '';

        // Basic table info using TableAccessService
        $tableLabel = TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));

        $result .= 'TABLE SCHEMA: ' . $table . ' (' . $tableLabel . ")\n";
        $result .= "=======================================\n\n";

        // Get access info from TableAccessService
        $accessInfo = $this->tableAccessService->getTableAccessInfo($table);

        $result .= "Type: content\n";
        $result .= 'Read-Only: ' . ($accessInfo['read_only'] ? 'Yes' : 'No') . "\n";
        if (!empty($accessInfo['restrictions'])) {
            $result .= 'Restrictions: ' . implode(', ', $accessInfo['restrictions']) . "\n";
        }
        $result .= "\n";

        // Add control fields section - only the most important ones
        $result .= "CONTROL FIELDS:\n";
        $result .= "--------------\n";

        $ctrl = $this->getTableCtrl($table);
        $importantFields = [
            'label', 'label_alt', 'descriptionColumn',
            'title', 'type', 'languageField', 'transOrigPointerField',
            'translationSource', 'searchFields',
        ];

        foreach ($importantFields as $key) {
            if (isset($ctrl[$key])) {
                $value = $ctrl[$key];
                if (is_array($value)) {
                    $encoded = json_encode($value);
                    $value = is_string($encoded) ? $encoded : '';
                } elseif (is_string($value) && str_starts_with($value, 'LLL:')) {
                    $value = TableAccessService::translateLabel($value);
                }
                if (is_scalar($value)) {
                    $result .= $key . ': ' . (string)$value . "\n";
                }
            }
        }

        $result .= "\n\n";

        // Get the type field using TableAccessService
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $excludeTypes = !empty($typeField) ? $this->getRemovedTypesByTSconfig($table, $typeField) : [];

        // Get available types using TableAccessService
        $types = $this->tableAccessService->getAvailableTypes($table);

        // Apply label translations and exclusions
        $processedTypes = [];
        foreach ($types as $value => $label) {
            // Skip excluded types
            if (in_array($value, $excludeTypes)) {
                continue;
            }

            $processedTypes[$value] = TableAccessService::translateLabel($label);
        }

        $types = $processedTypes;

        // If no types are available after filtering, show an error
        if (empty($types)) {
            return 'ERROR: No valid types available for this table after applying excludeTypes filter.';
        }

        // If a specific type is requested, check if it exists
        if (!empty($filterType) && !isset($types[$filterType])) {
            return "ERROR: The requested type '$filterType' does not exist or has been excluded. Available types are: " . implode(', ', array_keys($types));
        }
        // If no specific type is requested, use the first type that actually has a
        // showitem layout. Some tables (e.g. sys_file) only define a layout for one
        // type value while listing several in the type field's items list — picking
        // the first item alphabetically would land on a type without a form.
        if (empty($filterType)) {
            $tcaTypes = $this->getTableTypes($table);
            foreach ($types as $typeValue => $typeLabel) {
                if ($typeValue === '--div--') {
                    continue;
                }
                $typeValueString = (string)$typeValue;
                if (!empty($tcaTypes[$typeValueString]['showitem'])) {
                    $filterType = (string)$typeValue;
                    break;
                }
            }
            if (empty($filterType)) {
                foreach ($types as $typeValue => $typeLabel) {
                    if ($typeValue !== '--div--') {
                        $filterType = (string)$typeValue;
                        break;
                    }
                }
            }
        }

        // Get the type label
        $typeLabel = $types[$filterType] ?? '';

        // Add current record type section
        $result .= "CURRENT RECORD TYPE:\n";
        $result .= "-------------------\n";
        $result .= 'Type: ' . $filterType . ' (' . $typeLabel . ")\n\n";

        // Add fields section
        $result .= "FIELDS:\n";
        $result .= "-------\n";

        // Get available fields using TableAccessService (includes access control)
        $availableFields = $this->tableAccessService->getAvailableFields($table, $filterType);

        if (empty($availableFields)) {
            $result .= "No accessible fields defined for this type.\n";
            return $result;
        }
        // Get the type configuration to understand field organization (tabs, palettes).
        // showitem may be empty for tables where the chosen type has no backend form
        // (e.g. sys_file's "unknown" type, or any read-only table). In that case we
        // skip showitem-based grouping and let the "Additional Fields" section below
        // emit every accessible field — readers still need to see what's available.
        $typeConfig = $this->getTableTypes($table)[$filterType] ?? [];
        $showitem = is_string($typeConfig['showitem'] ?? null) ? $typeConfig['showitem'] : '';

        // Parse the showitem string for organization info (empty showitem yields no items)
        $fields = GeneralUtility::trimExplode(',', $showitem, true);

        // Group fields by tab
        $tabFields = [];
        $currentTab = 'General';

        foreach ($fields as $item) {
            $itemParts = GeneralUtility::trimExplode(';', $item, true);
            $fieldName = $itemParts[0];

            // Check if this is a tab
            if ($fieldName === '--div--') {
                $tabLabel = $itemParts[1] ?? 'Tab';
                $currentTab = $tabLabel; // Store the original label for later translation
                $tabFields[$currentTab] = [];
            } else {
                $tabFields[$currentTab][] = $item;
            }
        }

        // Process each tab's fields
        $processedFields = [];

        foreach ($tabFields as $tabName => $tabFieldsList) {
            $tabContent = '';

            foreach ($tabFieldsList as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $fieldName = $itemParts[0];

                // Check if this is a palette
                if ($fieldName === '--palette--' || str_starts_with((string)$fieldName, '--palette--')) {
                    // Extract palette name from the parts
                    $paletteParts = explode(';', $item);
                    $paletteName = $paletteParts[2] ?? '';
                    $paletteLabel = $paletteParts[1] ?? '';

                    // Translate the palette label if it's a language reference
                    if (!empty($paletteLabel)) {
                        $paletteLabel = TableAccessService::translateLabel($paletteLabel);
                    }

                    // Use palette name as fallback if label is empty
                    if (empty($paletteLabel)) {
                        $paletteLabel = ucfirst(str_replace('_', ' ', $paletteName));
                    }

                    $palettes = $this->getTablePalettes($table);
                    if (!empty($paletteName) && isset($palettes[$paletteName])) {
                        // Get the palette fields
                        $paletteFields = is_string($palettes[$paletteName]['showitem'] ?? null) ? $palettes[$paletteName]['showitem'] : '';
                        $paletteFieldsList = GeneralUtility::trimExplode(',', $paletteFields, true);

                        // Pre-filter to only items whose fields are accessible so we
                        // don't emit a palette header with no children.
                        $accessiblePaletteItems = [];
                        foreach ($paletteFieldsList as $paletteItem) {
                            $paletteItemParts = GeneralUtility::trimExplode(';', $paletteItem, true);
                            $paletteFieldName = $paletteItemParts[0];
                            if ($paletteFieldName === '--linebreak--') {
                                continue;
                            }
                            if (isset($availableFields[$paletteFieldName])) {
                                $accessiblePaletteItems[] = $paletteItem;
                            }
                        }

                        if (empty($accessiblePaletteItems)) {
                            continue;
                        }

                        $tabContent .= '    ┌─ (' . $paletteLabel . ")\n";

                        $lastPaletteField = end($accessiblePaletteItems);
                        reset($accessiblePaletteItems);

                        foreach ($accessiblePaletteItems as $paletteItem) {
                            $paletteItemParts = GeneralUtility::trimExplode(';', $paletteItem, true);
                            $paletteFieldName = $paletteItemParts[0];
                            $fieldConfig = $availableFields[$paletteFieldName];

                            // Mark as processed
                            $processedFields[$paletteFieldName] = true;

                            // Add the field to the result with proper indentation
                            $prefix = ($paletteItem === $lastPaletteField) ? '└─ ' : '├─ ';
                            $fieldLabel = $this->getFieldLabel($fieldConfig, $paletteFieldName);
                            // TcaSchemaFactory returns flattened config where type is at top level
                            $fieldType = $this->getFieldType($fieldConfig);
                            $tabContent .= '    ' . $prefix . $paletteFieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                            // Add field details inline
                            $this->addFieldDetailsInline($tabContent, $fieldConfig, $paletteFieldName, $table, $filterType);
                            $tabContent .= "\n";
                        }
                    }
                } else {
                    // Regular field
                    if (isset($availableFields[$fieldName])) {
                        $fieldConfig = $availableFields[$fieldName];

                        // Mark as processed
                        $processedFields[$fieldName] = true;

                        // Add the field to the result
                        $fieldLabel = $this->getFieldLabel($fieldConfig, $fieldName);
                        // TcaSchemaFactory returns flattened config where type is at top level
                        $fieldType = $this->getFieldType($fieldConfig);
                        $tabContent .= '    - ' . $fieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                        // Add field details inline
                        $this->addFieldDetailsInline($tabContent, $fieldConfig, $fieldName, $table, $filterType);
                        $tabContent .= "\n";
                    }
                }
            }

            // Skip tabs that have no accessible fields after filtering.
            if ($tabContent === '') {
                continue;
            }

            $translatedTabName = TableAccessService::translateLabel($tabName);
            $result .= '  (' . $translatedTabName . "):\n";
            $result .= $tabContent;
        }

        // Fields advertised by the schema but not present in the type's showitem
        // form layout fall here. Two real cases:
        //   - Dynamically wired-in fields like pi_flexform on tt_content `list`
        //     plugins, which the plugin registration adds outside the default
        //     showitem string.
        //   - Tables whose showitem is sparse (e.g. sys_file's only-defined type
        //     lists three form fields while the LLM cares about a dozen columns).
        // Computed read-only fields (mcp.computed=true) registered by
        // AfterSchemaLoadEvent listeners get their own labelled section so the
        // LLM knows they originate from outside the table and cannot be written.
        $additionalFields = [];
        $computedFields = [];
        foreach ($availableFields as $fieldName => $fieldConfig) {
            if (isset($processedFields[$fieldName])) {
                continue;
            }
            if ($this->isComputedField($fieldConfig)) {
                $computedFields[$fieldName] = $fieldConfig;
            } else {
                $additionalFields[$fieldName] = $fieldConfig;
            }
        }

        if (!empty($additionalFields)) {
            $result .= "  (Additional Fields):\n";
            foreach ($additionalFields as $fieldName => $fieldConfig) {
                $fieldLabel = $this->getFieldLabel($fieldConfig, $fieldName);
                $fieldType = $this->getFieldType($fieldConfig);
                $result .= '    - ' . $fieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                // Add field details inline
                $this->addFieldDetailsInline($result, $fieldConfig, $fieldName, $table, $filterType);
                $result .= "\n";
            }
        }

        if (!empty($computedFields)) {
            $result .= "  (Computed read-only — included by default, cannot be written):\n";
            foreach ($computedFields as $fieldName => $fieldConfig) {
                $fieldLabel = $this->getFieldLabel($fieldConfig, $fieldName);
                $fieldType = $this->getFieldType($fieldConfig);
                $result .= '    - ' . $fieldName . ' (' . $fieldLabel . '): ' . $fieldType;
                $description = $fieldConfig['description'] ?? null;
                if (is_string($description) && $description !== '') {
                    $result .= ' — ' . TableAccessService::translateLabel($description);
                }
                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Add field details inline
     */
    protected function addFieldDetailsInline(string &$result, array $fieldConfig, string $fieldName, string $table, string $filterType = ''): void
    {
        // Handle both flattened (from TcaSchemaFactory) and nested (traditional TCA) structures
        $config = $fieldConfig['config'] ?? $fieldConfig;
        $type = $config['type'] ?? '';

        // Add field details based on type
        if ($type === 'flex') {
            // For flex fields, show the available FlexForm identifiers
            $this->addFlexFormIdentifiers($result, $config, $table, $fieldName, $filterType);
        } else {
            // For other field types, use the TcaFormattingUtility
            TcaFormattingUtility::addFieldDetailsInline($result, $config, $fieldName, $table);
        }
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function getFieldLabel(array $fieldConfig, string $fallback): string
    {
        $label = $fieldConfig['label'] ?? null;
        return is_string($label) ? TableAccessService::translateLabel($label) : $fallback;
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function getFieldType(array $fieldConfig): string
    {
        $type = $fieldConfig['type'] ?? null;
        if (is_string($type) && $type !== '') {
            return $type;
        }
        $config = $fieldConfig['config'] ?? null;
        if (is_array($config) && is_string($config['type'] ?? null) && $config['type'] !== '') {
            return $config['type'];
        }
        return 'unknown';
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function isComputedField(array $fieldConfig): bool
    {
        $mcp = $fieldConfig['mcp'] ?? null;
        return is_array($mcp) && !empty($mcp['computed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getTableTca(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !isset($tca[$table]) || !is_array($tca[$table])) {
            return [];
        }
        $tableTca = [];
        foreach ($tca[$table] as $key => $value) {
            if (is_string($key)) {
                $tableTca[$key] = $value;
            }
        }
        return $tableTca;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTableCtrl(string $table): array
    {
        $ctrl = $this->getTableTca($table)['ctrl'] ?? null;
        return is_array($ctrl) ? $ctrl : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTableTypes(string $table): array
    {
        return $this->getTcaSubArray($table, 'types');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTablePalettes(string $table): array
    {
        return $this->getTcaSubArray($table, 'palettes');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTcaSubArray(string $table, string $key): array
    {
        $value = $this->getTableTca($table)[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $name => $config) {
            if (is_array($config)) {
                $normalized[(string)$name] = $config;
            }
        }
        return $normalized;
    }

    /**
     * Add FlexForm identifiers to the result
     */
    protected function addFlexFormIdentifiers(string &$result, array $config, string $table, string $fieldName, string $filterType = ''): void
    {
        $result .= ' (FlexForm)';

        // Get available FlexForm identifiers
        if (isset($config['ds']) && is_array($config['ds'])) {
            $identifiers = array_keys($config['ds']);

            // Filter out default identifier
            $identifiers = array_filter($identifiers, fn($id) => $id !== 'default');

            // Filter identifiers based on the requested type
            if (!empty($filterType) && !empty($config['ds_pointerField'])) {
                $pointerFields = GeneralUtility::trimExplode(',', $config['ds_pointerField'], true);

                // Filter identifiers that match the current type
                // Either directly or with a wildcard
                $filteredIdentifiers = [];
                foreach ($identifiers as $id) {
                    if (str_contains((string)$id, ',')) {
                        $parts = explode(',', (string)$id);
                        // Check if the identifier matches the current type
                        // Either directly or with a wildcard
                        if (($parts[0] === '*' && $parts[1] === $filterType) ||
                            ($parts[1] === '*' && $parts[0] === $filterType) ||
                            ($parts[0] === $filterType) ||
                            ($parts[1] === $filterType)) {
                            $filteredIdentifiers[] = $id;
                        }
                    } elseif ($id === $filterType) {
                        $filteredIdentifiers[] = $id;
                    }
                }

                // Use filtered identifiers if any were found
                if (!empty($filteredIdentifiers)) {
                    $identifiers = $filteredIdentifiers;
                }
            }

            if (!empty($identifiers)) {
                $result .= ' [Identifiers: ' . implode(', ', $identifiers) . ']';
                $result .= ' (Use GetFlexFormSchema tool with these identifiers for details)';
            }
        }

        // Add ds_pointerField information if available
        if (isset($config['ds_pointerField'])) {
            $result .= ' [ds_pointerField: ' . $config['ds_pointerField'] . ']';
        }
    }

    /**
     * Get types that are removed by TSconfig
     * This uses the same logic as TcaSelectItems to determine which types are restricted
     */
    protected function getRemovedTypesByTSconfig(string $table, string $typeField): array
    {
        if (empty($table) || empty($typeField)) {
            return [];
        }

        $removedTypes = [];

        // Get TSconfig for the current page
        $TSconfig = BackendUtility::getPagesTSconfig(0);

        // Check TCEFORM.[table].[field].removeItems
        $fieldTSconfig = $TSconfig['TCEFORM.'][$table . '.'][$typeField . '.']['removeItems'] ?? '';
        if (!empty($fieldTSconfig)) {
            $removedTypes = GeneralUtility::trimExplode(',', $fieldTSconfig, true);
        }

        // For tt_content, also check TCEMAIN.table.tt_content.disableCTypes
        if ($table === 'tt_content' && $typeField === 'CType') {
            $disableCTypes = $TSconfig['TCEMAIN.']['table.']['tt_content.']['disableCTypes'] ?? '';
            if (!empty($disableCTypes)) {
                $disabledTypes = GeneralUtility::trimExplode(',', $disableCTypes, true);
                $removedTypes = array_merge($removedTypes, $disabledTypes);
            }
        }

        return $removedTypes;
    }

}
