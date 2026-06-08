<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Condenses MCP tool schemas before they are sent in a `tools/list` response.
 *
 * The `tools/list` payload is injected into the context window of every MCP
 * session before the user types anything, so verbose tool/field descriptions
 * are a fixed token cost paid on every conversation. When the extension
 * setting `schemaDetail` is `concise` (the default), this service trims long
 * descriptions down to their leading sentences while preserving sentences that
 * carry critical gotchas (REQUIRED, MUST, REPLACES, …). The full, verbatim
 * schema of any tool stays available on demand through the GetCapabilities
 * tool, and operators can restore the old behaviour globally with
 * `schemaDetail = full`.
 *
 * The transform only ever touches human-readable `description` strings. All
 * structural JSON Schema keywords (type, enum, required, items, default, …)
 * are left untouched so client-side validation keeps working.
 */
final class ToolSchemaOptimizer
{
    /** Character budget for the top-level tool description. */
    private const TOP_LEVEL_BUDGET = 220;

    /** Character budget for each per-field description. */
    private const FIELD_BUDGET = 110;

    /**
     * Sentences containing one of these markers are kept even when they fall
     * past the budget, up to a hard ceiling of 2x the budget. They flag
     * behaviour an LLM must know to call the tool correctly.
     *
     * @var list<string>
     */
    private const CRITICAL_MARKERS = [
        'CRITICAL',
        'REQUIRED',
        'MUST',
        'NEVER',
        'REPLACES',
        'WARNING',
        'IMPORTANT',
        'irreversible',
        'DELETES',
    ];

    public function __construct(
        private readonly ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * Whether tool schemas should be condensed for `tools/list`.
     */
    public function isConcise(): bool
    {
        $config = null;
        $extensionConfiguration = $this->extensionConfiguration;
        if ($extensionConfiguration === null) {
            try {
                $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            } catch (\Throwable) {
                return true;
            }
        }

        try {
            $config = $extensionConfiguration->get('mcp_server');
        } catch (\Throwable) {
            return true;
        }

        $value = is_array($config) ? ($config['schemaDetail'] ?? null) : null;
        if (!is_string($value) || $value === '') {
            return true;
        }

        return strtolower(trim($value)) !== 'full';
    }

    /**
     * Return a (possibly condensed) copy of a tool schema. In `full` mode the
     * schema is returned unchanged.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function optimize(array $schema): array
    {
        if (!$this->isConcise()) {
            return $schema;
        }

        if (isset($schema['description']) && is_string($schema['description'])) {
            $schema['description'] = $this->condense($schema['description'], self::TOP_LEVEL_BUDGET);
        }

        $inputSchema = $schema['inputSchema'] ?? null;
        if (is_array($inputSchema) && isset($inputSchema['properties']) && is_array($inputSchema['properties'])) {
            $properties = $inputSchema['properties'];
            foreach ($properties as $name => $property) {
                if (is_array($property) && isset($property['description']) && is_string($property['description'])) {
                    $property['description'] = $this->condense($property['description'], self::FIELD_BUDGET);
                    $properties[$name] = $property;
                }
            }
            $inputSchema['properties'] = $properties;
            $schema['inputSchema'] = $inputSchema;
        }

        return $schema;
    }

    /**
     * Keep the leading sentences of a description up to the budget, then append
     * any later sentence that carries a critical marker (bounded by 2x budget).
     * A trailing ellipsis signals that detail was dropped.
     */
    private function condense(string $text, int $budget): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $budget) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', $text);
        if ($sentences === false) {
            return $text;
        }
        $sentences = array_values(array_filter(array_map('trim', $sentences), static fn(string $s): bool => $s !== ''));
        if ($sentences === []) {
            return $text;
        }

        $hardLimit = $budget * 2;
        $kept = [$sentences[0]];
        $included = [0 => true];
        $used = mb_strlen($sentences[0]);

        $count = count($sentences);
        for ($i = 1; $i < $count; $i++) {
            $length = mb_strlen($sentences[$i]) + 1;
            if ($used + $length > $budget) {
                break;
            }
            $kept[] = $sentences[$i];
            $included[$i] = true;
            $used += $length;
        }

        // Preserve critical gotchas from the dropped remainder, within the ceiling.
        for ($i = 1; $i < $count; $i++) {
            if (isset($included[$i]) || !$this->isCritical($sentences[$i])) {
                continue;
            }
            $length = mb_strlen($sentences[$i]) + 1;
            if ($used + $length > $hardLimit) {
                continue;
            }
            $kept[] = $sentences[$i];
            $included[$i] = true;
            $used += $length;
        }

        $result = implode(' ', $kept);
        if (count($included) < $count) {
            $result = rtrim($result) . ' …';
        }

        return $result;
    }

    private function isCritical(string $sentence): bool
    {
        foreach (self::CRITICAL_MARKERS as $marker) {
            if (str_contains($sentence, $marker)) {
                return true;
            }
        }
        return false;
    }
}
