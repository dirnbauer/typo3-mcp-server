<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\AbstractInvalidDataStructureException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Normalizes MCP write payloads into DataHandler-ready field values.
 */
final readonly class RecordDataWriteConverter
{
    private const DEFAULT_FLEXFORM_SHEET = 'sDEF';

    public function __construct(
        private TableAccessService $tableAccessService,
        private FlexFormTools $flexFormTools,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param int|null $uid The record being updated. Its stored record type
     *                      selects the FlexForm DataStructure when $data does
     *                      not set the type field itself.
     * @return array<string, mixed>
     */
    public function convert(string $table, array $data, ?int $uid = null): array
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
                    $data[$fieldName] = $this->buildFlexFormXml($table, $fieldName, $fieldConfigSettings, $flexFormArray, $data, $uid);
                }
            }
        }

        return $data;
    }

    /**
     * Build FlexForm XML in the shape FormEngine stores: every value sits in
     * the sheet its DataStructure declares, under its full dotted field name.
     *
     * FlexFormTools::flexArray2Xml() is the serializer DataHandler uses. The
     * field name goes into an index attribute there; a plain array2xml() call
     * would use it as the tag name and strip the dots from it.
     *
     * @param array<string, mixed> $fieldConfig
     * @param array<array-key, mixed> $values
     * @param array<string, mixed> $record
     */
    private function buildFlexFormXml(
        string $table,
        string $fieldName,
        array $fieldConfig,
        array $values,
        array $record,
        ?int $uid,
    ): string {
        $fieldSheets = $this->resolveFlexFormFieldSheets($table, $fieldName, $fieldConfig, $record, $uid);

        $sheets = [self::DEFAULT_FLEXFORM_SHEET => ['lDEF' => []]];
        foreach ($this->flattenFlexFormValues($values) as $flexFieldName => $flexFieldValue) {
            $sheet = $fieldSheets[$flexFieldName] ?? self::DEFAULT_FLEXFORM_SHEET;
            $sheets[$sheet]['lDEF'][$flexFieldName]['vDEF'] = $flexFieldValue;
        }

        return $this->flexFormTools->flexArray2Xml(['data' => $sheets]);
    }

    /**
     * Map every field of the record's DataStructure to its sheet, using the
     * DataStructure DataHandler and FormEngine resolve for the same record.
     * An unresolvable DataStructure yields an empty map, so every value falls
     * back to the default sheet.
     *
     * @param array<string, mixed> $fieldConfig
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function resolveFlexFormFieldSheets(string $table, string $fieldName, array $fieldConfig, array $record, ?int $uid): array
    {
        if (($fieldConfig['type'] ?? '') !== 'flex' || !$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $schema = $this->tcaSchemaFactory->get($table);
        $row = $uid !== null && $uid > 0 ? (BackendUtility::getRecordWSOL($table, $uid) ?? []) : [];
        foreach ($record as $recordField => $recordValue) {
            if (is_scalar($recordValue)) {
                $row[$recordField] = $recordValue;
            }
        }

        try {
            $identifier = $this->flexFormTools->getDataStructureIdentifier(['config' => $fieldConfig], $table, $fieldName, $row, $schema);
            $dataStructure = $this->flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
        } catch (AbstractInvalidDataStructureException) {
            return [];
        }

        $fieldSheets = [];
        $dataStructureSheets = is_array($dataStructure['sheets'] ?? null) ? $dataStructure['sheets'] : [];
        foreach ($dataStructureSheets as $sheetName => $sheet) {
            $sheetFields = is_array($sheet) && is_array($sheet['ROOT'] ?? null) && is_array($sheet['ROOT']['el'] ?? null)
                ? $sheet['ROOT']['el']
                : [];
            foreach (array_keys($sheetFields) as $dataStructureFieldName) {
                $fieldSheets[(string)$dataStructureFieldName] = (string)$sheetName;
            }
        }

        return $fieldSheets;
    }

    /**
     * Turn nested input such as {"settings": {"media": {"maxWidth": 800}}}
     * into the dotted field names a DataStructure declares
     * ("settings.media.maxWidth"). A list of scalars is the value of one
     * multi-value field and is stored comma-separated, the way TYPO3 stores
     * select and group values.
     *
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function flattenFlexFormValues(array $values, string $prefix = ''): array
    {
        $flattened = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (!is_array($value)) {
                $flattened[$path] = is_bool($value) ? $this->scalarToFlexFormString($value) : $value;
                continue;
            }
            $listValue = array_is_list($value) ? $this->implodeScalarList($value) : null;
            if ($listValue !== null) {
                $flattened[$path] = $listValue;
                continue;
            }
            $flattened += $this->flattenFlexFormValues($value, $path);
        }

        return $flattened;
    }

    /**
     * @param list<mixed> $values
     */
    private function implodeScalarList(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $items = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return null;
            }
            $items[] = $this->scalarToFlexFormString($value);
        }

        return implode(',', $items);
    }

    /**
     * FormEngine stores a checkbox as "1" or "0"; a JSON false would
     * otherwise become an empty string.
     */
    private function scalarToFlexFormString(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string)$value;
    }
}
