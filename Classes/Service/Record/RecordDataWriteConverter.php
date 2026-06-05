<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Normalizes MCP write payloads into DataHandler-ready field values.
 */
final readonly class RecordDataWriteConverter
{
    public function __construct(
        private TableAccessService $tableAccessService,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convert(string $table, array $data): array
    {
        foreach ($data as $fieldName => $value) {
            if ($value === null) {
                continue;
            }

            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            $fieldConfigSettings = is_array($fieldConfig['config'] ?? null) ? $fieldConfig['config'] : [];
            if (($fieldConfigSettings['type'] ?? '') === 'slug' && is_string($value)) {
                $data[$fieldName] = '/' . trim($value, '/');
            }

            $fieldType = $fieldConfigSettings['type'] ?? '';
            if ($fieldType === 'imageManipulation' && is_array($value)) {
                $data[$fieldName] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                continue;
            }

            if ($this->tableAccessService->isFlexFormField($table, $fieldName)) {
                if (is_string($value) && str_starts_with($value, '<?xml')) {
                    continue;
                }

                $flexFormArray = is_array($value) ? $value : (is_string($value) && str_starts_with($value, '{') ? json_decode($value, true) : null);

                if (is_array($flexFormArray)) {
                    $flexFormData = [
                        'data' => [
                            'sDEF' => [
                                'lDEF' => [],
                            ],
                        ],
                    ];

                    if (isset($flexFormArray['settings']) && is_array($flexFormArray['settings'])) {
                        foreach ($flexFormArray['settings'] as $settingKey => $settingValue) {
                            $flexFormData['data']['sDEF']['lDEF']['settings.' . $settingKey]['vDEF'] = $settingValue;
                        }
                    }

                    foreach ($flexFormArray as $key => $val) {
                        if ($key !== 'settings' && !is_array($val)) {
                            $flexFormData['data']['sDEF']['lDEF'][$key]['vDEF'] = $val;
                        }
                    }

                    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n";
                    $xml .= GeneralUtility::array2xml($flexFormData, '', 0, 'T3FlexForms');

                    $data[$fieldName] = $xml;
                }
            }
        }

        return $data;
    }
}
