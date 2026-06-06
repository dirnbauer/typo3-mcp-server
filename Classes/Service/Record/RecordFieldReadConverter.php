<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Service\FlexFormService;

final readonly class RecordFieldReadConverter
{
    public function __construct(
        private TableAccessService $tableAccessService,
    ) {}
    public function processRecord(array $record, string $table, array $requestedFields = []): array
    {
        $processedRecord = [];

        // For workspace transparency, replace workspace UID with live UID
        if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
            // This is a workspace version of an existing record - use the live UID instead
            $record['uid'] = $record['t3ver_oid'];
        } elseif (isset($record['t3ver_state']) && (int)$record['t3ver_state'] === 1) {
            // This is a new record in workspace - keep its UID as is
            // New records don't have a live counterpart until published
            // No change needed
        }

        // Ensure uid is always in the requested fields when a field list is specified
        if (!empty($requestedFields) && !in_array('uid', $requestedFields)) {
            $requestedFields[] = 'uid';
        }

        // Build the set of fields the schema lets through. Always include essential
        // ctrl fields (uid, pid, timestamps, etc.) since they are valid to read but
        // are typically absent from TCA showitem definitions.
        $essentialFields = $this->tableAccessService->getEssentialFields($table);
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $recordType = ($typeField && isset($record[$typeField])) ? (string)$record[$typeField] : '';
        $availableFields = $this->tableAccessService->getAvailableFields($table, $recordType);
        $allowedFields = array_unique(array_merge(array_keys($availableFields), $essentialFields));

        // Process each field
        foreach ($record as $field => $value) {
            // Special handling for pi_flexform in list content elements
            if ($field === 'pi_flexform' && $table === 'tt_content' &&
                isset($record['CType']) && $record['CType'] === 'list' &&
                !empty($record['list_type'])) {
                // Check if there's a FlexForm DS configured for this plugin
                $flexFormDs = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config']['ds'] ?? [];
                $listType = $record['list_type'];

                // Check various DS key patterns
                $hasFlexFormConfig = isset($flexFormDs[$listType . ',list']) ||
                                    isset($flexFormDs['*,' . $listType]) ||
                                    isset($flexFormDs[$listType]);

                if ($hasFlexFormConfig) {
                    // Include pi_flexform for this plugin
                    $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
                    continue;
                }
            }

            // Schema filter: drop fields not advertised by getAvailableFields(). Computed
            // fields registered via AfterSchemaLoadEvent are advertised and pass through
            // the same as any TCA column — they are part of the default response.
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }

            // Skip fields not in the requested field list
            if (!empty($requestedFields) && !in_array($field, $requestedFields)) {
                continue;
            }

            // Include the field
            $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
        }

        // Belt-and-suspenders: workspace plumbing fields must never reach the
        // MCP client even if a TCA showitem accidentally lists one. The
        // workspace transparency contract requires only live UIDs to be
        // exposed; t3ver_* are an internal implementation detail.
        unset(
            $processedRecord['t3ver_oid'],
            $processedRecord['t3ver_wsid'],
            $processedRecord['t3ver_state'],
            $processedRecord['t3ver_stage'],
            $processedRecord['t3ver_tstamp'],
            $processedRecord['t3ver_count'],
        );

        return $processedRecord;
    }

    public function convertFieldValue(string $table, string $field, $value)
    {
        // Skip null values
        if ($value === null) {
            return null;
        }

        // Check if this is an integer field based on TCA eval rules or select field with integer values
        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $field);
        if ($fieldConfig) {
            // Check eval rules for int
            if (isset($fieldConfig['config']['eval']) && str_contains($fieldConfig['config']['eval'], 'int')) {
                return (int)$value;
            }

            // Check if it's a select field with numeric string that should be integer
            if (isset($fieldConfig['config']['type']) && $fieldConfig['config']['type'] === 'select') {
                // If the value is numeric, check if this field typically uses integers
                if (is_numeric($value)) {
                    // Special handling for common integer fields
                    if (in_array($field, ['type', 'sys_language_uid', 'colPos', 'layout', 'frame_class', 'space_before_class', 'space_after_class', 'header_layout'])) {
                        return (int)$value;
                    }

                    // Check if ALL items use integer values (not just one)
                    if (!empty($fieldConfig['config']['items'])) {
                        $allIntegers = true;
                        $hasItems = false;

                        foreach ($fieldConfig['config']['items'] as $item) {
                            $itemValue = null;
                            if (isset($item['value'])) {
                                $itemValue = $item['value'];
                            } elseif (isset($item[1])) {
                                $itemValue = $item[1];
                            }

                            if ($itemValue !== null && $itemValue !== '--div--') {
                                $hasItems = true;
                                if (!is_int($itemValue) && (!is_scalar($itemValue) || !ctype_digit((string)$itemValue))) {
                                    $allIntegers = false;
                                    break;
                                }
                            }
                        }

                        // Only convert if all items are integers
                        if ($hasItems && $allIntegers) {
                            return (int)$value;
                        }
                    }
                }
            }
        }

        // Convert FlexForm XML to JSON
        if ($this->tableAccessService->isFlexFormField($table, $field) && is_string($value) && !empty($value) && str_starts_with($value, '<?xml')) {
            try {
                // Use TYPO3's FlexFormService to convert XML to array
                $flexFormService = new FlexFormService();
                $flexFormArray = $flexFormService->convertFlexFormContentToArray($value);

                // Simplify the structure for easier use in LLMs
                $result = [];
                $settings = [];

                // Process each field and organize settings
                foreach ($flexFormArray as $key => $val) {
                    // Check if this is a settings field (key starts with "settings")
                    if (str_starts_with($key, 'settings')   && strlen($key) > 8) {
                        // Extract the setting name (remove "settings" prefix)
                        $settingName = substr($key, 8);
                        // Convert first character to lowercase if it's uppercase
                        if (ctype_upper($settingName[0])) {
                            $settingName = lcfirst($settingName);
                        }
                        $settings[$settingName] = $val;
                    } else {
                        $result[$key] = $val;
                    }
                }

                // Add settings to result if any were found
                if (!empty($settings)) {
                    $result['settings'] = $settings;
                }

                return $result;
            } catch (\Exception) {
                // Log the error but continue with empty result
                // flexform parse failed
                return [];
            }
        }

        // Convert JSON strings to arrays
        if (is_string($value) && str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Convert timestamps to ISO 8601 dates
        if (is_numeric($value) && $this->tableAccessService->isDateField($table, $field)) {
            if ($value > 0) {
                $dateTime = new \DateTime('@' . $value);
                $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $dateTime->format('c');
            }
            return null;
        }

        return $value;
    }

}
