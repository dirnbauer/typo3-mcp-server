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
    protected function getTableCtrl(string $table): array
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
     * @return array<string, mixed>
     */
    protected function getTypeConfig(string $table, string $type): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return [];
        }

        $types = $tableConfig['types'] ?? null;
        if (!\is_array($types)) {
            return [];
        }

        $typeConfig = $types[$type] ?? null;
        return \is_array($typeConfig) ? $typeConfig : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getPaletteConfig(string $table, string $paletteName): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!\is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!\is_array($tableConfig)) {
            return [];
        }

        $palettes = $tableConfig['palettes'] ?? null;
        if (!\is_array($palettes)) {
            return [];
        }

        $paletteConfig = $palettes[$paletteName] ?? null;
        return \is_array($paletteConfig) ? $paletteConfig : [];
    }

    protected function getDefaultPageTsconfig(): string
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
     * Get the tool schema
     *
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
                        'description' => 'Optional specific type to include (e.g., "text" for tt_content). If not provided, will show the first available type and a summary of all types.',
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
    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $table = \is_string($params['table'] ?? null) ? $params['table'] : '';

        if (empty($table)) {
            throw new \InvalidArgumentException('Table parameter is required');
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, 'read');

        $filterType = \is_string($params['type'] ?? null) ? $params['type'] : '';

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
                // Format arrays as JSON
                if (\is_array($value)) {
                    $value = json_encode($value) ?: '[]';
                } elseif (\is_string($value) && str_starts_with($value, 'LLL:')) {
                    // Translate LLL keys
                    $value = TableAccessService::translateLabel($value);
                }
                $result .= $key . ': ' . (\is_scalar($value) ? (string)$value : '') . "\n";
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
            if (\in_array($value, $excludeTypes)) {
                continue;
            }

            $processedTypes[$value] = TableAccessService::translateLabel($label);
        }

        $types = $processedTypes;

        // If no types are available after filtering, show an error
        if (empty($types)) {
            return 'ERROR: No valid types available for this table after applying excludeTypes filter.';
        }

        $result .= "AVAILABLE TYPES:\n";
        $result .= "----------------\n";
        foreach ($types as $typeValue => $availableTypeLabel) {
            if ($typeValue === '--div--') {
                continue;
            }

            $result .= '- ' . $typeValue . ' (' . $availableTypeLabel . ")\n";
        }
        $result .= "\n";

        if ($table === 'tt_content') {
            $result .= "Plugin content types may expose additional FlexForm configuration in `pi_flexform`.\n\n";
        }

        // If a specific type is requested, check if it exists
        if (!empty($filterType) && !isset($types[$filterType])) {
            return "ERROR: The requested type '$filterType' does not exist or has been excluded. Available types are: " . implode(', ', array_keys($types));
        }

        // If no specific type is requested, use the first available type
        if (empty($filterType)) {
            // Skip dividers when selecting the default type
            foreach ($types as $typeValue => $typeLabel) {
                if ($typeValue !== '--div--') {
                    $filterType = (string)$typeValue;
                    break;
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

        // Get the type configuration to understand field organization (tabs, palettes)
        $typeConfig = $this->getTypeConfig($table, $filterType);
        $showitem = \is_string($typeConfig['showitem'] ?? null) ? $typeConfig['showitem'] : '';

        if (empty($showitem)) {
            $result .= "No field layout defined for this type.\n";
            return $result;
        }

        // Parse the showitem string for organization info
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
            // Translate the tab name
            $translatedTabName = TableAccessService::translateLabel($tabName);
            $result .= '  (' . $translatedTabName . "):\n";

            foreach ($tabFieldsList as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $fieldName = $itemParts[0];

                // Check if this is a palette
                if ($fieldName === '--palette--' || str_starts_with($fieldName, '--palette--')) {
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

                    if ($paletteName !== '' && $this->getPaletteConfig($table, $paletteName) !== []) {
                        // Add the palette to the current tab's fields
                        $result .= '    ┌─ (' . $paletteLabel . ")\n";

                        // Get the palette fields
                        $paletteConfig = $this->getPaletteConfig($table, $paletteName);
                        $paletteFields = \is_string($paletteConfig['showitem'] ?? null) ? $paletteConfig['showitem'] : '';
                        $paletteFieldsList = GeneralUtility::trimExplode(',', $paletteFields, true);

                        // Process each palette field
                        $lastPaletteField = end($paletteFieldsList);
                        reset($paletteFieldsList);

                        foreach ($paletteFieldsList as $paletteItem) {
                            $paletteItemParts = GeneralUtility::trimExplode(';', $paletteItem, true);
                            $paletteFieldName = $paletteItemParts[0];

                            // Skip special fields
                            if ($paletteFieldName === '--linebreak--') {
                                continue;
                            }

                            // Add the field to the result if it's accessible
                            if (isset($availableFields[$paletteFieldName])) {
                                $fieldConfig = $availableFields[$paletteFieldName];

                                // Mark as processed
                                $processedFields[$paletteFieldName] = true;

                                // Add the field to the result with proper indentation
                                $prefix = ($paletteItem === $lastPaletteField) ? '└─ ' : '├─ ';
                                $fieldLabel = \is_string($fieldConfig['label'] ?? null) ? TableAccessService::translateLabel($fieldConfig['label']) : $paletteFieldName;
                                // TcaSchemaFactory returns flattened config where type is at top level
                                $nestedConfig = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
                                $fieldType = \is_string($fieldConfig['type'] ?? null) ? $fieldConfig['type'] : (\is_string($nestedConfig['type'] ?? null) ? $nestedConfig['type'] : 'unknown');
                                $result .= '    ' . $prefix . $paletteFieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                                // Add field details inline
                                $this->addFieldDetailsInline($result, $fieldConfig, $paletteFieldName, $table, $filterType);
                                $result .= "\n";
                            }
                        }
                    }
                } else {
                    // Regular field
                    if (isset($availableFields[$fieldName])) {
                        $fieldConfig = $availableFields[$fieldName];

                        // Mark as processed
                        $processedFields[$fieldName] = true;

                        // Add the field to the result
                        $fieldLabel = \is_string($fieldConfig['label'] ?? null) ? TableAccessService::translateLabel($fieldConfig['label']) : $fieldName;
                        // TcaSchemaFactory returns flattened config where type is at top level
                        $nestedConfig = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
                        $fieldType = \is_string($fieldConfig['type'] ?? null) ? $fieldConfig['type'] : (\is_string($nestedConfig['type'] ?? null) ? $nestedConfig['type'] : 'unknown');
                        $result .= '    - ' . $fieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                        // Add field details inline
                        $this->addFieldDetailsInline($result, $fieldConfig, $fieldName, $table, $filterType);
                        $result .= "\n";
                    }
                }
            }
        }

        // Check for fields that are available but not in showitem (e.g., dynamically added fields like pi_flexform for plugins)
        $unassignedFields = [];
        foreach ($availableFields as $fieldName => $fieldConfig) {
            if (!isset($processedFields[$fieldName])) {
                $unassignedFields[$fieldName] = $fieldConfig;
            }
        }

        // If there are unassigned fields, add them to a special section
        if (!empty($unassignedFields)) {
            $result .= "  (Additional Fields):\n";
            foreach ($unassignedFields as $fieldName => $fieldConfig) {
                $fieldLabel = \is_string($fieldConfig['label'] ?? null) ? TableAccessService::translateLabel($fieldConfig['label']) : $fieldName;
                $nestedConfig = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
                $fieldType = \is_string($fieldConfig['type'] ?? null) ? $fieldConfig['type'] : (\is_string($nestedConfig['type'] ?? null) ? $nestedConfig['type'] : 'unknown');
                $result .= '    - ' . $fieldName . ' (' . $fieldLabel . '): ' . $fieldType;

                // Add field details inline
                $this->addFieldDetailsInline($result, $fieldConfig, $fieldName, $table, $filterType);
                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Add field details inline
     *
     * @param array<string, mixed> $fieldConfig
     */
    protected function addFieldDetailsInline(string &$result, array $fieldConfig, string $fieldName, string $table, string $filterType = ''): void
    {
        // Handle both flattened (from TcaSchemaFactory) and nested (traditional TCA) structures
        $config = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : $fieldConfig;
        $type = \is_string($config['type'] ?? null) ? $config['type'] : '';

        // Add field details based on type
        if ($type === 'flex') {
            // For flex fields, show the available FlexForm identifiers
            $config = $this->getEffectiveFlexFormConfig($config, $table, $fieldName, $filterType);
            $this->addFlexFormIdentifiers($result, $config, $table, $fieldName, $filterType);
        } else {
            // For other field types, use the TcaFormattingUtility
            TcaFormattingUtility::addFieldDetailsInline($result, $config, $fieldName, $table);
        }
    }

    /**
     * Add FlexForm identifiers to the result
     *
     * @param array<string, mixed> $config
     */
    protected function addFlexFormIdentifiers(string &$result, array $config, string $table, string $fieldName, string $filterType = ''): void
    {
        $result .= ' (FlexForm)';

        // Get available FlexForm identifiers
        if (isset($config['ds']) && \is_array($config['ds'])) {
            $identifiers = array_keys($config['ds']);

            // Filter out default identifier
            $identifiers = array_values(array_filter($identifiers, static fn(string $id): bool => $id !== 'default'));

            // Filter identifiers based on the requested type
            if ($filterType !== '' && \is_string($config['ds_pointerField'] ?? null) && $config['ds_pointerField'] !== '') {
                // Filter identifiers that match the current type
                // Either directly or with a wildcard
                $filteredIdentifiers = [];
                foreach ($identifiers as $id) {
                    if (str_contains((string)$id, ',')) {
                        $parts = explode(',', (string)$id);
                        // Check if the identifier matches the current type
                        // Either directly or with a wildcard
                        if (($parts[0] === '*' && $parts[1] === $filterType)
                            || ($parts[1] === '*' && $parts[0] === $filterType)
                            || ($parts[0] === $filterType)
                            || ($parts[1] === $filterType)) {
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
        } elseif ($filterType !== '') {
            $result .= ' [Identifiers: *,' . $filterType . ']';
            $result .= ' (Use GetFlexFormSchema tool with these identifiers for details)';
        }

        // Add ds_pointerField information if available
        if (\is_string($config['ds_pointerField'] ?? null)) {
            $result .= ' [ds_pointerField: ' . $config['ds_pointerField'] . ']';
        } elseif ($table === 'tt_content' && $filterType !== '') {
            $result .= ' [ds_pointerField: CType]';
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function getEffectiveFlexFormConfig(array $config, string $table, string $fieldName, string $filterType): array
    {
        if ($filterType === '') {
            return $config;
        }

        $typeConfig = $this->getTypeConfig($table, $filterType);
        $columnsOverrides = $typeConfig['columnsOverrides'] ?? null;
        $overrideFieldConfig = \is_array($columnsOverrides) ? ($columnsOverrides[$fieldName] ?? null) : null;
        $overrideConfig = \is_array($overrideFieldConfig) && \is_array($overrideFieldConfig['config'] ?? null) ? $overrideFieldConfig['config'] : null;
        if (!\is_array($overrideConfig)) {
            return $config;
        }

        return array_replace_recursive($config, $overrideConfig);
    }

    /**
     * Get types that are removed by TSconfig
     * This uses the same logic as TcaSelectItems to determine which types are restricted
     *
     * @return list<string>
     */
    protected function getRemovedTypesByTSconfig(string $table, string $typeField): array
    {
        if (empty($table) || empty($typeField)) {
            return [];
        }

        $removedTypes = [];

        // Get TSconfig for the current page
        $TSconfig = BackendUtility::getPagesTSconfig(0);
        if (($TSconfig['TCEFORM.'] ?? null) === null && ($TSconfig['TCEMAIN.'] ?? null) === null) {
            $TSconfig = BackendUtility::getPagesTSconfig(1);
        }

        // Check TCEFORM.[table].[field].removeItems
        $fieldTSconfig = $TSconfig['TCEFORM.'][$table . '.'][$typeField . '.']['removeItems'] ?? '';
        if (!empty($fieldTSconfig)) {
            $removedTypes = GeneralUtility::trimExplode(',', $fieldTSconfig, true);
        }

        $defaultPageTsconfig = $this->getDefaultPageTsconfig();
        if ($defaultPageTsconfig !== '') {
            if (preg_match(
                '/^\s*TCEFORM\.' . preg_quote($table, '/') . '\.' . preg_quote($typeField, '/') . '\.removeItems\s*=\s*(.+)\s*$/m',
                $defaultPageTsconfig,
                $matches,
            ) === 1) {
                $removedTypes = array_merge($removedTypes, GeneralUtility::trimExplode(',', trim($matches[1]), true));
            }
        }

        // For tt_content, also check TCEMAIN.table.tt_content.disableCTypes
        if ($table === 'tt_content' && $typeField === 'CType') {
            $disableCTypes = $TSconfig['TCEMAIN.']['table.']['tt_content.']['disableCTypes'] ?? '';
            if (!empty($disableCTypes)) {
                $disabledTypes = GeneralUtility::trimExplode(',', $disableCTypes, true);
                $removedTypes = array_merge($removedTypes, $disabledTypes);
            }

            if ($defaultPageTsconfig !== '' && preg_match(
                '/^\s*TCEMAIN\.table\.tt_content\.disableCTypes\s*=\s*(.+)\s*$/m',
                $defaultPageTsconfig,
                $matches,
            ) === 1) {
                $removedTypes = array_merge($removedTypes, GeneralUtility::trimExplode(',', trim($matches[1]), true));
            }
        }

        return $removedTypes;
    }

}
