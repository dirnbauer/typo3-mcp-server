<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Utility\TcaFormattingUtility;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for getting FlexForm schema information
 *
 * @phpstan-type TcaConfig array<string, mixed>
 * @phpstan-type FlexConfigCandidate array{type: ?string, config: TcaConfig}
 * @phpstan-type ProcessedField array{name: string, type: string, label: string, description: string, config: TcaConfig, jsonPath: string}
 * @phpstan-type ProcessedSheet array{name: string|null, fields: list<ProcessedField>}
 * @phpstan-type ProcessedFlexForm array{sheets: list<ProcessedSheet>, fields: list<string>, hasSheets: bool}
 */
final class GetFlexFormSchemaTool extends AbstractRecordTool
{
    /**
     * @return array<string, mixed>
     */
    protected function getColumnConfig(string $table, string $field): array
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

        $columnConfig = $columns[$field] ?? null;
        return \is_array($columnConfig) ? $columnConfig : [];
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
     * @param array<mixed, mixed> $array
     * @return array<string, mixed>
     */
    protected function normalizeAssocArray(array $array): array
    {
        $normalized = [];
        foreach ($array as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Get the tool schema
     *
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Get schema information for a specific FlexForm field. Returns field definitions, types, and configuration options for the FlexForm DataStructure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name containing the FlexForm field (default: tt_content)',
                        'default' => 'tt_content',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name containing the FlexForm data (default: pi_flexform)',
                        'default' => 'pi_flexform',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'The FlexForm identifier (e.g., "form_formframework", "*,news_pi1"). In TYPO3 v14, plugin CTypes typically use the CType-based pattern; legacy list-based plugins may still use list_type-based identifiers.',
                    ],
                    'recordUid' => [
                        'type' => 'integer',
                        'description' => 'Optional record UID (currently not used but accepted for compatibility)',
                    ],
                ],
                'required' => ['identifier'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    /**
     * Execute the tool logic
     *
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Get parameters
        $table = \is_string($params['table'] ?? null) ? $params['table'] : 'tt_content';
        $field = \is_string($params['field'] ?? null) ? $params['field'] : 'pi_flexform';
        $identifier = \is_string($params['identifier'] ?? null) ? $params['identifier'] : '';

        // Validate parameters
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Identifier parameter is required');
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, 'read');

        // Check if the table and field exist
        $columnConfig = $this->getColumnConfig($table, $field);
        if ($columnConfig === []) {
            throw new \InvalidArgumentException("Field '$field' not found in table '$table'");
        }

        // Check if the field is a FlexForm field
        $flexFormConfig = isset($columnConfig['config']) && \is_array($columnConfig['config']) ? $columnConfig['config'] : [];
        if (($flexFormConfig['type'] ?? null) !== 'flex') {
            throw new \InvalidArgumentException("Field '$field' in table '$table' is not a FlexForm field");
        }

        // Special handling for form_formframework
        if ($identifier === 'form_formframework') {
            $identifier = '*,form_formframework';
        }

        $resolution = $this->resolveFlexFormDataStructure($table, $field, $identifier);
        $resolvedIdentifier = $resolution['identifier'];
        $dsValue = $resolution['dsValue'];

        if ($dsValue === null) {
            throw new \InvalidArgumentException("FlexForm schema not found for identifier: $resolvedIdentifier");
        }

        // Build the header
        $header = "FLEXFORM SCHEMA: $resolvedIdentifier\n";
        $header .= "=======================================\n\n";
        $header .= "Table: $table\n";
        $header .= "Field: $field\n\n";

        // Handle FILE: references
        if (\is_string($dsValue) && str_starts_with($dsValue, 'FILE:')) {
            $file = substr($dsValue, 5);
            $file = GeneralUtility::getFileAbsFileName($file);
            $prefix = 'Schema defined in file: ' . $file . "\n\n";

            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (!empty($content)) {
                    // Parse the XML content using TYPO3's built-in method
                    $xmlArray = GeneralUtility::xml2array($content);

                    if (\is_array($xmlArray)) {
                        $processedData = $this->processFlexFormXml($xmlArray);
                        $result = $this->formatFlexFormSchema($processedData, $header . $prefix);
                        return $this->createSuccessResult($result);
                    }
                    throw new \RuntimeException("Failed to parse XML schema from file: $file");
                }
                throw new \RuntimeException("FlexForm file is empty: $file");
            }
            throw new \RuntimeException("FlexForm file not found: $file");
        }

        if (\is_string($dsValue)) {
            $prefix = "Schema defined inline as XML\n\n";

            // Parse the XML content using TYPO3's built-in method
            $xmlArray = GeneralUtility::xml2array($dsValue);

            if (\is_array($xmlArray)) {
                $processedData = $this->processFlexFormXml($xmlArray);
                $result = $this->formatFlexFormSchema($processedData, $header . $prefix);
                return $this->createSuccessResult($result);
            }
            throw new \RuntimeException('Failed to parse inline XML schema');
        }

        if (\is_array($dsValue)) {
            // PHP array format - process directly
            $processedData = $this->processFlexFormXml($dsValue);
            $prefix = "Schema defined as PHP array\n\n";
            $result = $this->formatFlexFormSchema($processedData, $header . $prefix);
            return $this->createSuccessResult($result);
        }

        throw new \RuntimeException('Unsupported FlexForm data structure configuration');
    }

    /**
     * Resolve a requested identifier to an existing DS key.
     */
    protected function resolveFlexFormIdentifier(mixed $dsConfig, string $identifier, ?string $type = null): ?string
    {
        if (\is_string($dsConfig) && $dsConfig !== '') {
            return $type !== null ? $this->formatTypeScopedIdentifier($identifier, $type) : null;
        }

        if (!\is_array($dsConfig) || $dsConfig === []) {
            return null;
        }

        $identifierValue = $this->getIdentifierValue($identifier);
        $candidates = array_values(array_unique(array_filter([
            $identifier,
            !str_contains($identifier, ',') ? '*,' . $identifier : null,
            $identifierValue,
            $identifierValue !== '' ? '*,' . $identifierValue : null,
            $type,
            $type !== null ? '*,' . $type : null,
        ], static fn(mixed $candidate): bool => \is_string($candidate) && $candidate !== '')));

        foreach ($candidates as $candidate) {
            if (\array_key_exists($candidate, $dsConfig)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve the effective FlexForm data structure for an identifier.
     *
     * @return array{identifier: string, dsValue: mixed}
     */
    protected function resolveFlexFormDataStructure(string $table, string $field, string $identifier): array
    {
        foreach ($this->getFlexFormConfigCandidates($table, $field, $identifier) as $candidate) {
            $resolvedIdentifier = $this->resolveFlexFormIdentifier(
                $candidate['config']['ds'] ?? null,
                $identifier,
                $candidate['type'],
            );

            if ($resolvedIdentifier === null) {
                continue;
            }

            $dsValue = $this->extractFlexFormDataStructure($candidate['config']['ds'] ?? null, $resolvedIdentifier);
            if ($dsValue !== null) {
                return [
                    'identifier' => $resolvedIdentifier,
                    'dsValue' => $dsValue,
                ];
            }
        }

        return [
            'identifier' => $this->formatNotFoundIdentifier($identifier),
            'dsValue' => null,
        ];
    }

    /**
     * @return list<FlexConfigCandidate>
     */
    protected function getFlexFormConfigCandidates(string $table, string $field, string $identifier): array
    {
        $candidates = [];
        $columnConfig = $this->getColumnConfig($table, $field);
        $baseConfig = isset($columnConfig['config']) && \is_array($columnConfig['config']) ? $columnConfig['config'] : [];

        foreach ($this->getTypeCandidatesFromIdentifier($identifier) as $type) {
            $typeConfig = $this->getTypeConfig($table, $type);
            $columnsOverrides = $typeConfig['columnsOverrides'] ?? null;
            $overrideFieldConfig = \is_array($columnsOverrides) ? ($columnsOverrides[$field] ?? null) : null;
            $overrideConfig = \is_array($overrideFieldConfig) && \is_array($overrideFieldConfig['config'] ?? null) ? $overrideFieldConfig['config'] : null;
            if (\is_array($overrideConfig)) {
                $candidates[] = [
                    'type' => $type,
                    'config' => array_replace_recursive($baseConfig, $overrideConfig),
                ];
            }
        }

        $candidates[] = [
            'type' => null,
            'config' => $baseConfig,
        ];

        return $candidates;
    }

    /**
     * @return list<string>
     */
    protected function getTypeCandidatesFromIdentifier(string $identifier): array
    {
        $candidates = [];
        foreach (explode(',', $identifier) as $part) {
            $part = trim($part);
            if ($part !== '' && $part !== '*') {
                $candidates[] = $part;
            }
        }

        return array_values(array_unique($candidates));
    }

    protected function extractFlexFormDataStructure(mixed $dsConfig, string $resolvedIdentifier): mixed
    {
        if (\is_string($dsConfig) && $dsConfig !== '') {
            return $dsConfig;
        }

        if (\is_array($dsConfig) && \array_key_exists($resolvedIdentifier, $dsConfig)) {
            return $dsConfig[$resolvedIdentifier];
        }

        return null;
    }

    protected function formatNotFoundIdentifier(string $identifier): string
    {
        return $identifier;
    }

    protected function formatTypeScopedIdentifier(string $identifier, string $type): string
    {
        if (str_contains($identifier, ',')) {
            return $identifier;
        }

        if ($identifier === $type) {
            return '*,' . $type;
        }

        return '*,' . $identifier;
    }

    protected function getIdentifierValue(string $identifier): string
    {
        $parts = array_values(array_filter(
            array_map(trim(...), explode(',', $identifier)),
            static fn(string $part): bool => $part !== '' && $part !== '*',
        ));

        return $parts[\count($parts) - 1] ?? $identifier;
    }

    /**
     * Convert dot notation field name to JSON path
     * e.g., "settings.orderBy" -> "pi_flexform.settings.orderBy"
     */
    protected function getJsonPath(string $fieldName): string
    {
        if (!str_contains($fieldName, '.')) {
            return 'pi_flexform.' . $fieldName;
        }

        $parts = explode('.', $fieldName);
        return 'pi_flexform.' . implode('.', $parts);
    }

    /**
     * Process a single field configuration
     *
     * @param string $fieldName The field name
     * @param array<string, mixed> $field The field configuration
     * @return ProcessedField Processed field data with type, label, description, etc.
     */
    protected function processField(string $fieldName, array $field): array
    {
        $fieldData = [
            'name' => $fieldName,
            'type' => 'unknown',
            'label' => $fieldName,
            'description' => '',
            'config' => [],
            'jsonPath' => $this->getJsonPath($fieldName),
        ];

        // Check if field uses TCEforms structure (older format) or direct configuration (newer format)
        $fieldConfig = isset($field['TCEforms']) && \is_array($field['TCEforms']) ? $field['TCEforms'] : $field;

        // Get field label
        if (\is_string($fieldConfig['label'] ?? null)) {
            $fieldData['label'] = TableAccessService::translateLabel($fieldConfig['label']);
        }

        // Get field type and config
        $config = isset($fieldConfig['config']) && \is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        if (\is_string($config['type'] ?? null)) {
            $fieldData['type'] = $config['type'];
            $fieldData['config'] = $config;
        }

        // Get field description
        if (\is_string($fieldConfig['description'] ?? null)) {
            $fieldData['description'] = TableAccessService::translateLabel($fieldConfig['description']);
        }

        return $fieldData;
    }

    /**
     * Process a collection of fields
     *
     * @param array<string, mixed> $fields The fields to process
     * @return list<ProcessedField> Array of processed field data
     */
    protected function processFields(array $fields): array
    {
        $processedFields = [];

        foreach ($fields as $fieldName => $field) {
            if (!\is_string($fieldName) || !\is_array($field)) {
                continue;
            }
            $processedFields[] = $this->processField($fieldName, $this->normalizeAssocArray($field));
        }

        return $processedFields;
    }

    /**
     * Process FlexForm sheets
     *
     * @param array<string, mixed> $sheets The sheets to process
     * @return list<ProcessedSheet> Processed sheets data
     */
    protected function processSheets(array $sheets): array
    {
        $processedSheets = [];

        foreach ($sheets as $sheetName => $sheet) {
            if (!\is_string($sheetName) || !\is_array($sheet)) {
                continue;
            }
            $sheetData = [
                'name' => $sheetName,
                'fields' => [],
            ];

            $root = isset($sheet['ROOT']) && \is_array($sheet['ROOT']) ? $sheet['ROOT'] : [];
            $elements = isset($root['el']) && \is_array($root['el']) ? $root['el'] : [];
            if ($elements !== []) {
                $sheetData['fields'] = $this->processFields($this->normalizeAssocArray($elements));
            }

            $processedSheets[] = $sheetData;
        }

        return $processedSheets;
    }

    /**
     * Process FlexForm XML structure
     *
     * @param array<mixed, mixed> $xmlArray The parsed XML array
     * @return ProcessedFlexForm Processed FlexForm data
     */
    protected function processFlexFormXml(array $xmlArray): array
    {
        $xmlArray = $this->normalizeAssocArray($xmlArray);
        $data = [
            'sheets' => [],
            'fields' => [],
            'hasSheets' => false,
        ];

        if (isset($xmlArray['sheets']) && \is_array($xmlArray['sheets'])) {
            // Multi-sheet FlexForm
            $data['hasSheets'] = true;
            $data['sheets'] = $this->processSheets($this->normalizeAssocArray($xmlArray['sheets']));

            // Collect all field names for JSON example
            foreach ($data['sheets'] as $sheet) {
                foreach ($sheet['fields'] as $field) {
                    $data['fields'][] = $field['name'];
                }
            }
        } else {
            $root = isset($xmlArray['ROOT']) && \is_array($xmlArray['ROOT']) ? $xmlArray['ROOT'] : [];
            $elements = isset($root['el']) && \is_array($root['el']) ? $root['el'] : [];
            if ($elements === []) {
                return $data;
            }
            // Single sheet FlexForm
            $processedFields = $this->processFields($this->normalizeAssocArray($elements));
            $data['fields'] = array_column($processedFields, 'name');

            // Store as single unnamed sheet for consistency
            $data['sheets'][] = [
                'name' => null,
                'fields' => $processedFields,
            ];
        }

        return $data;
    }

    /**
     * Format processed FlexForm data as text
     *
     * @param ProcessedFlexForm $data Processed FlexForm data
     * @param string $prefix Additional prefix text
     * @return string Formatted text output
     */
    protected function formatFlexFormSchema(array $data, string $prefix = ''): string
    {
        $result = $prefix;

        if ($data['hasSheets']) {
            $result .= "SHEETS:\n";
            $result .= "-------\n";

            foreach ($data['sheets'] as $sheet) {
                $result .= "Sheet: {$sheet['name']}\n";
                $result .= "  Fields:\n";

                foreach ($sheet['fields'] as $field) {
                    $result .= $this->formatField($field, '  ');
                }

                $result .= "\n";
            }
        } else {
            $result .= "FIELDS:\n";
            $result .= "------\n";

            if (!empty($data['sheets'][0]['fields'])) {
                foreach ($data['sheets'][0]['fields'] as $field) {
                    $result .= $this->formatField($field, '');
                }
            }

            $result .= "\n";
        }

        // Add JSON structure example
        $result .= "JSON STRUCTURE:\n";
        $result .= "==============\n";
        $result .= "When reading or writing FlexForm data, use nested objects/arrays:\n\n";

        if (!empty($data['fields'])) {
            $jsonExample = $this->buildJsonExample($data['fields']);
            $result .= json_encode($jsonExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $result .= json_encode(['pi_flexform' => ['example' => 'This is an example of the FlexForm data structure']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $result .= "\n\nNote: Field names with dots (e.g., \"settings.orderBy\") are automatically\n";
        $result .= 'converted to nested structures by TYPO3.';

        return $result;
    }

    /**
     * Format a single field for text output
     *
     * @param ProcessedField $field The field data
     * @param string $indent Indentation prefix
     * @return string Formatted field text
     */
    protected function formatField(array $field, string $indent): string
    {
        $result = $indent . "- {$field['name']}";

        if ($field['label'] !== $field['name']) {
            $result .= " ({$field['label']})";
        }

        $result .= ": {$field['type']}";

        // Add field details based on type
        if (!empty($field['config'])) {
            TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
        }

        if (!empty($field['description'])) {
            $result .= " - {$field['description']}";
        }

        $result .= "\n";
        $result .= $indent . "  JSON Path: {$field['jsonPath']}\n";

        return $result;
    }

    /**
     * Build example JSON structure from field names
     *
     * @param list<string> $fieldNames
     * @return array<string, mixed>
     */
    protected function buildJsonExample(array $fieldNames): array
    {
        $example = ['pi_flexform' => []];

        foreach ($fieldNames as $fieldName) {
            if (!\is_string($fieldName)) {
                continue;
            }
            // Skip non-field entries
            if (!str_contains($fieldName, '.')) {
                $example['pi_flexform'][$fieldName] = '<' . $fieldName . ' value>';
            } else {
                // Handle nested structure
                $parts = explode('.', $fieldName);
                $this->assignNestedExampleValue($example['pi_flexform'], $parts);
            }
        }

        return $example;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string> $parts
     */
    protected function assignNestedExampleValue(array &$root, array $parts): void
    {
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return;
        }

        $current = &$root;
        $lastIndex = \count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($index === $lastIndex) {
                $current[$part] = '<' . $part . ' value>';
                return;
            }

            if (!isset($current[$part]) || !\is_array($current[$part])) {
                $current[$part] = [];
            }
            /** @var array<string, mixed> $next */
            $next = &$current[$part];
            $current = &$next;
        }
    }

}
