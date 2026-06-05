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
                $tables[$table] = $tableConfig;
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

        return is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
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
                $normalizedColumns[$fieldName] = $fieldConfig;
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
    public function getTypeConfig(string $table, string $type): array
    {
        $types = $this->getTable($table)['types'] ?? [];

        if (!is_array($types)) {
            return [];
        }

        $typeConfig = $types[$type] ?? [];

        return is_array($typeConfig) ? $typeConfig : [];
    }

    public function hasTable(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table);
    }
}
