<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the full list of select field items using TYPO3's FormDataCompiler.
 *
 * This runs the same pipeline as the backend FormEngine, including:
 * - Static TCA items
 * - foreign_table items
 * - itemsProcFunc / itemsProcessors callbacks
 * - TSconfig addItems / removeItems / keepItems
 * - authMode filtering
 * - Language and doktype restrictions
 */
final class SelectItemResolver
{
    /**
     * Runtime cache for compiled form data, keyed by table, pid, and record context.
     *
     * @var array<string, array>
     */
    private array $cache = [];

    /**
     * Resolve the fully processed list of select items for a field.
     *
     * @param string $table Table name
     * @param string $field Field name
     * @param array $record Record context (used for pid resolution and itemsProcFunc context).
     *                      For updates: merge existing DB record with submitted data.
     *                      For creates: submitted data with pid.
     *                      For schema display: empty array (pid defaults to 0).
     * @return array|null Array with 'values' and 'labels' keys, or null on failure
     */
    public function resolveSelectItems(string $table, string $field, array $record = []): ?array
    {
        if (empty($table) || empty($field)) {
            return null;
        }

        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!$fieldConfig || ($fieldConfig['type'] ?? '') !== 'select') {
            return null;
        }

        try {
            $formData = $this->compileFormData($table, $record);
        } catch (\Throwable) {
            // FormDataCompiler can fail for various reasons (missing DB records, etc.)
            // Return null to signal callers to use their fallback logic
            return null;
        }

        $items = $formData['processedTca']['columns'][$field]['config']['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return $this->parseItems($items);
    }

    /**
     * Compile form data using TYPO3's FormDataCompiler.
     *
     * The record's scalar field values are fed into the compiler as defaultValues
     * (the same mechanism the backend uses for &defVals[...] when opening a new
     * record). DatabaseRowInitializeNew copies them into the databaseRow that
     * itemsProcFunc callbacks receive as $parameters['row'].
     *
     * @param string $table Table name
     * @param array $record Record context for databaseRow and pid resolution
     * @return array The compiled form data
     */
    private function compileFormData(string $table, array $record): array
    {
        $pid = (int)($record['pid'] ?? 0);
        $rowValues = $this->extractRowValues($table, $record);
        $cacheKey = $table . ':' . $pid . ':' . md5(serialize($rowValues));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $request = $this->createMinimalServerRequest($pid);

        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);

        $input = [
            'request' => $request,
            'tableName' => $table,
            'vanillaUid' => $pid,
            'command' => 'new',
            'defaultValues' => $rowValues === [] ? [] : [$table => $rowValues],
        ];

        $restoreTca = $this->disableNoMatchingValueElement($table);
        try {
            $result = $formDataCompiler->compile($input, $formDataGroup);
        } finally {
            $restoreTca();
        }
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Temporarily suppress FormEngine's invalid-value pseudo items for select fields.
     *
     * When a submitted select value is seeded into defaultValues, FormEngine may add
     * it back as an "[ invalid value ]" option to avoid data loss in backend forms.
     * For MCP validation that would make every submitted value appear allowed.
     *
     * @return callable(): void
     */
    private function disableNoMatchingValueElement(string $table): callable
    {
        $columns = $this->getTcaColumns($table);
        if ($columns === []) {
            return static function (): void {};
        }

        /** @var array<string, mixed> $originals */
        $originals = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (!is_string($fieldName) || !is_array($fieldConfig)) {
                continue;
            }

            $config = $fieldConfig['config'] ?? [];
            if (!is_array($config) || ($config['type'] ?? '') !== 'select') {
                continue;
            }

            $originals[$fieldName] = $config['disableNoMatchingValueElement'] ?? null;
            $config['disableNoMatchingValueElement'] = true;
            $fieldConfig['config'] = $config;
            $columns[$fieldName] = $fieldConfig;
        }

        if ($originals === []) {
            return static function (): void {};
        }

        $this->replaceTcaColumns($table, $columns);

        return function () use ($table, $originals): void {
            $columns = $this->getTcaColumns($table);
            foreach ($originals as $fieldName => $original) {
                $fieldConfig = $columns[$fieldName] ?? null;
                if (!is_array($fieldConfig)) {
                    continue;
                }

                $config = $fieldConfig['config'] ?? [];
                if (!is_array($config)) {
                    $config = [];
                }

                if ($original === null) {
                    unset($config['disableNoMatchingValueElement']);
                } else {
                    $config['disableNoMatchingValueElement'] = $original;
                }

                $fieldConfig['config'] = $config;
                $columns[$fieldName] = $fieldConfig;
            }

            $this->replaceTcaColumns($table, $columns);
        };
    }

    /**
     * Reduce the record context to scalar TCA fields that can seed databaseRow.
     *
     * @param string $table Table name
     * @param array<array-key, mixed> $record Record context
     * @return array<string, scalar> Field name => value
     */
    private function extractRowValues(string $table, array $record): array
    {
        $columns = $this->getTcaColumns($table);
        /** @var array<string, scalar> $rowValues */
        $rowValues = [];
        foreach ($record as $fieldName => $value) {
            if (!is_string($fieldName)) {
                continue;
            }
            if ($fieldName === 'pid') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            if (!isset($columns[$fieldName])) {
                continue;
            }

            $rowValues[$fieldName] = $value;
        }

        ksort($rowValues);

        return $rowValues;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTcaColumns(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return [];
        }

        $tableConfig = $tca[$table] ?? null;
        if (!is_array($tableConfig)) {
            return [];
        }

        $columns = $tableConfig['columns'] ?? null;
        if (!is_array($columns)) {
            return [];
        }

        /** @var array<string, mixed> $columns */
        return $columns;
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function replaceTcaColumns(string $table, array $columns): void
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return;
        }

        $tableConfig = $tca[$table] ?? null;
        if (!is_array($tableConfig)) {
            return;
        }

        $tableConfig['columns'] = $columns;
        $tca[$table] = $tableConfig;
        $GLOBALS['TCA'] = $tca;
    }

    /**
     * Create a minimal PSR-7 ServerRequest with site context for TSconfig resolution.
     */
    private function createMinimalServerRequest(int $pid): ServerRequestInterface
    {
        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ];

        $request = new ServerRequest('http://localhost/', 'GET', 'php://input', [], $serverParams);

        // Try to attach site context for proper TSconfig resolution
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($pid);
            $request = $request->withAttribute('site', $site);
            $request = $request->withAttribute('language', $site->getDefaultLanguage());
        } catch (\Throwable) {
            // No site found for this pid — proceed without site context.
            // TSconfig resolution will still work for global TSconfig.
        }

        $normalizedParams = NormalizedParams::createFromServerParams($serverParams);
        $request = $request->withAttribute('normalizedParams', $normalizedParams);

        return $request;
    }

    /**
     * Parse resolved items into the values/labels structure.
     *
     * @param array $items Resolved items from FormDataCompiler
     * @return array Array with 'values' and 'labels' keys
     */
    private function parseItems(array $items): array
    {
        $result = [
            'values' => [],
            'labels' => [],
        ];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? ($item[1] ?? '');
            $label = $item['label'] ?? ($item[0] ?? '');

            // Skip dividers
            if ((string)$value === '--div--') {
                continue;
            }

            $stringValue = (string)$value;
            if ($stringValue !== '') {
                $result['values'][] = $stringValue;
                $result['labels'][$stringValue] = $label;
            }
        }

        return $result;
    }
}
