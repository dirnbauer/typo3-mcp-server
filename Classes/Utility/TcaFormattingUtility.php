<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility for formatting TCA and FlexForm information
 */
final class TcaFormattingUtility
{
    /**
     * Add field details inline for TCA or FlexForm configuration
     *
     * @param string &$result The result string to append to
     * @param array<string, mixed> $config The field configuration
     * @param string $fieldName Optional field name for special handling
     * @param string $table Optional table name for authMode filtering
     */
    public static function addFieldDetailsInline(string &$result, array $config, string $fieldName = '', string $table = '', ?int $pid = null): void
    {
        // Get the field type
        $type = is_string($config['type'] ?? null) ? $config['type'] : '';
        $softref = is_string($config['softref'] ?? null) ? $config['softref'] : '';
        $eval = is_string($config['eval'] ?? null) ? $config['eval'] : '';
        $size = is_scalar($config['size'] ?? null) ? (string)$config['size'] : null;
        $max = is_scalar($config['max'] ?? null) ? (string)$config['max'] : null;
        $cols = is_scalar($config['cols'] ?? null) ? (string)$config['cols'] : null;
        $rows = is_scalar($config['rows'] ?? null) ? (string)$config['rows'] : null;
        $defaultValue = is_scalar($config['default'] ?? null) ? (string)$config['default'] : null;
        $renderType = is_scalar($config['renderType'] ?? null) ? (string)$config['renderType'] : null;
        $foreignTable = is_scalar($config['foreign_table'] ?? null) ? (string)$config['foreign_table'] : null;
        $mmTable = is_scalar($config['MM'] ?? null) ? (string)$config['MM'] : null;
        $allowed = is_scalar($config['allowed'] ?? null) ? (string)$config['allowed'] : null;
        $dsPointerField = is_scalar($config['ds_pointerField'] ?? null) ? (string)$config['ds_pointerField'] : null;

        // Add field details based on type
        switch ($type) {
            case 'input':
                if ($size !== null) {
                    $result .= ' [size: ' . $size . ']';
                }
                if ($max !== null) {
                    $result .= ' [max: ' . $max . ']';
                }

                // Check for typolink support via softref
                if ($softref !== '' && str_contains($softref, 'typolink_tag')) {
                    $result .= ' [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]';
                }
                break;

            case 'text':
                if ($cols !== null) {
                    $result .= ' [cols: ' . $cols . ']';
                }
                if ($rows !== null) {
                    $result .= ' [rows: ' . $rows . ']';
                }

                // Check for richtext enabled
                if (isset($config['enableRichtext']) && $config['enableRichtext']) {
                    $result .= ' [Richtext/HTML]';
                }

                // Check for typolink support via softref
                if ($softref !== '' && str_contains($softref, 'typolink_tag')) {
                    $result .= ' [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]';
                }
                break;

            case 'check':
                if ($defaultValue !== null) {
                    $result .= ' [Default: ' . $defaultValue . ']';
                }
                break;

            case 'select':
                // Add renderType if available
                if ($renderType !== null) {
                    $result .= ' [renderType: ' . $renderType . ']';
                }

                // Add foreign table and MM information
                if ($foreignTable !== null) {
                    $result .= ' [foreign table: ' . $foreignTable . ']';
                }
                if ($mmTable !== null) {
                    $result .= ' [MM table: ' . $mmTable . ']';
                }

                // Add select options if available
                $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
                $optionLabels = [];

                $typeField = $table !== '' ? $tableAccessService->getTypeFieldName($table) : null;
                if ($typeField !== null && $fieldName === $typeField) {
                    foreach ($tableAccessService->getAvailableTypes($table, $pid) as $value => $label) {
                        $optionLabels[(string)$value] = $label;
                    }
                } elseif (isset($config['items']) && is_array($config['items'])) {
                    $parsed = $tableAccessService->parseSelectItems($config['items'], false); // Include dividers
                    foreach ($parsed['values'] as $value) {
                        $optionLabels[(string)$value] = $parsed['labels'][$value] ?? '';
                    }
                }

                if ($optionLabels !== []) {
                    // Check if this field has authMode restrictions
                    $hasAuthMode = !empty($config['authMode']);
                    $beUser = $GLOBALS['BE_USER'] ?? null;
                    $isAdmin = $beUser instanceof BackendUserAuthentication && $beUser->isAdmin();

                    $options = [];
                    foreach ($optionLabels as $value => $label) {
                        // Skip dividers
                        if ($value === '--div--') {
                            continue;
                        }

                        // Filter by authMode for non-admin users
                        if ($hasAuthMode && !$isAdmin && $beUser instanceof BackendUserAuthentication && !empty($table) && !empty($fieldName)) {
                            if (!$beUser->checkAuthMode($table, $fieldName, $value)) {
                                continue; // User doesn't have permission for this value
                            }
                        }

                        if ($label !== '') {
                            $translatedLabel = TableAccessService::translateLabel((string)$label);
                            $options[] = $value . ' (' . $translatedLabel . ')';
                        } else {
                            $options[] = $value;
                        }
                    }

                    if (!empty($options)) {
                        $result .= ' [Options: ' . implode(', ', $options) . ']';
                    }
                }

                // Special handling for sys_language_uid field
                if ($fieldName === 'sys_language_uid') {
                    // Add note about ISO code support
                    $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                    $isoCodes = $languageService->getAvailableIsoCodes();

                    if (!empty($isoCodes)) {
                        $result .= ' [ISO codes accepted: ' . implode(', ', $isoCodes) . ']';
                        $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
                    }
                }
                break;

            case 'group':
                // Add allowed table if available
                if ($allowed !== null) {
                    $result .= ' [allowed: ' . $allowed . ']';
                }
                break;

            case 'inline':
                // Add foreign table if available
                if ($foreignTable !== null) {
                    $result .= ' [foreign table: ' . $foreignTable . ']';
                }
                break;

            case 'flex':
                // Only applicable for TCA
                if ($dsPointerField !== null) {
                    $result .= ' [ds_pointerField: ' . $dsPointerField . ']';
                }
                break;

            case 'language':
                // Special handling for language type fields.
                // Add note about ISO code support
                $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                $isoCodes = $languageService->getAvailableIsoCodes();

                if (!empty($isoCodes)) {
                    $result .= ' [ISO codes accepted: ' . implode(', ', $isoCodes) . ']';
                    $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
                }
                break;
        }

        // Add required flag if set
        if ($eval !== '' && str_contains($eval, 'required')) {
            $result .= ' [Required]';
        }

        // Add default value if set
        if ($defaultValue !== null && $type !== 'check') {
            $result .= ' [Default: ' . $defaultValue . ']';
        }
    }

}
