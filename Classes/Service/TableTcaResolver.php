<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Low-level TCA accessors shared by table access and schema presentation services.
 */
final readonly class TableTcaResolver
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllTables(): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($globalTca)) {
            return [];
        }

        $tables = [];
        foreach ($globalTca as $table => $tableConfig) {
            if (is_string($table) && is_array($tableConfig)) {
                $tables[$table] = $this->stringKeyed($tableConfig);
            }
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTable(string $table): array
    {
        return $this->getAllTables()[$table] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCtrl(string $table): array
    {
        $tca = $this->getTable($table);

        return is_array($tca['ctrl'] ?? null) ? $this->stringKeyed($tca['ctrl']) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getColumns(string $table): array
    {
        $columns = $this->getTable($table)['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $normalizedColumns = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (is_string($fieldName) && is_array($fieldConfig)) {
                $normalizedColumns[$fieldName] = $this->stringKeyed($fieldConfig);
            }
        }

        return $normalizedColumns;
    }

    /**
     * @return array<string, mixed>
     */
    public function getField(string $table, string $fieldName): array
    {
        return $this->getColumns($table)[$fieldName] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldConfig(string $table, string $fieldName): array
    {
        $config = $this->getField($table, $fieldName)['config'] ?? [];

        return is_array($config) ? $this->stringKeyed($config) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTypeConfig(string $table, string $type): array
    {
        $types = $this->getTable($table)['types'] ?? [];

        if (!is_array($types)) {
            return [];
        }

        $typeConfig = $types[$type] ?? [];

        return is_array($typeConfig) ? $this->stringKeyed($typeConfig) : [];
    }

    public function hasTable(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table);
    }

    /**
     * TCA configuration maps use string keys at the level exposed by this service.
     * Ignore malformed numeric keys instead of leaking an inaccurate return type.
     *
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
