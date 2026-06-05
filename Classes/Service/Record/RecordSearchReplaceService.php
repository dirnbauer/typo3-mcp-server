<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Resolves MCP search-and-replace field operations for WriteTableTool updates.
 */
final readonly class RecordSearchReplaceService
{
    /** @var list<string> */
    private const STRING_FIELD_TYPES = ['input', 'text', 'email', 'link', 'slug', 'color'];

    public function __construct(
        private TableAccessService $tableAccessService,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array<string, list<array{search: string, replace: string, replaceAll?: bool}>>
     */
    public function extractFromData(string $table, array &$data, string $action): array
    {
        $searchReplace = [];

        foreach ($data as $fieldName => $value) {
            if (!is_array($value)) {
                continue;
            }

            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if ($fieldConfig && in_array($fieldConfig['config']['type'] ?? '', ['inline', 'file'], true)) {
                continue;
            }

            if (!$this->isSearchReplaceArray($value)) {
                continue;
            }

            if ($action !== 'update') {
                throw new ValidationException(["Search-and-replace operations in data are only supported for the \"update\" action (field '{$fieldName}')"]);
            }

            foreach ($value as $index => $operation) {
                if ($operation['search'] === '') {
                    throw new ValidationException(["Field '{$fieldName}' search-and-replace operation at index {$index} has an empty search string"]);
                }
            }

            $searchReplace[$fieldName] = $value;
            unset($data[$fieldName]);
        }

        return $searchReplace;
    }

    /**
     * @param array<string, list<array{search: string, replace: string, replaceAll?: bool}>> $searchReplace
     * @return array<string, string>
     */
    public function resolve(string $table, int $uid, array $searchReplace): array
    {
        foreach (array_keys($searchReplace) as $fieldName) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                throw new ValidationException(["search_replace field '{$fieldName}' does not exist in table '{$table}'"]);
            }
            if (!$this->tableAccessService->canAccessField($table, $fieldName)) {
                throw new ValidationException(["Field '{$fieldName}' is not accessible"]);
            }
            $fieldType = $fieldConfig['config']['type'] ?? '';
            if (!in_array($fieldType, self::STRING_FIELD_TYPES, true)) {
                throw new ValidationException(["search_replace is not supported for field '{$fieldName}' (type: {$fieldType}). Only string fields (text, input, etc.) are supported."]);
            }
        }

        $record = BackendUtility::getRecord($table, $uid);
        if (!$record) {
            throw new ValidationException(["Record {$uid} not found in table '{$table}'"]);
        }
        BackendUtility::workspaceOL($table, $record);

        $resolved = [];
        foreach ($searchReplace as $fieldName => $operations) {
            $currentValue = (string)($record[$fieldName] ?? '');

            foreach ($operations as $index => $operation) {
                $search = $operation['search'];
                $replaceAll = !empty($operation['replaceAll']);
                $replace = $operation['replace'];

                $count = substr_count($currentValue, (string)$search);

                if ($count === 0) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string not found in current field value"]);
                }

                if ($count > 1 && !$replaceAll) {
                    throw new ValidationException(["search_replace field '{$fieldName}' operation {$index}: Search string found {$count} times, must be unique. Set replaceAll to true to replace all occurrences."]);
                }

                if ($replaceAll) {
                    $currentValue = str_replace($search, $replace, $currentValue);
                } else {
                    $pos = strpos($currentValue, (string)$search);
                    $currentValue = substr_replace($currentValue, $replace, (int)$pos, strlen((string)$search));
                }
            }

            $resolved[$fieldName] = $currentValue;
        }

        return $resolved;
    }

    /**
     * @param list<array{search: string, replace: string, replaceAll?: bool}> $value
     */
    public function isSearchReplaceArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
            if (!isset($item['search']) || !is_string($item['search'])) {
                return false;
            }
            if (!array_key_exists('replace', $item) || !is_string($item['replace'])) {
                return false;
            }
        }

        return true;
    }
}
