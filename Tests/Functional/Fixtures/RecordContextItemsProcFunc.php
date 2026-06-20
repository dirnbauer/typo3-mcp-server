<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures;

/**
 * Test itemsProcFunc that only exposes values derived from the current row.
 */
final class RecordContextItemsProcFunc
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function itemsProcFunc(array &$parameters): void
    {
        $parameters['items'][] = [
            'label' => 'Default',
            'value' => 'default',
        ];

        $row = is_array($parameters['row'] ?? null) ? $parameters['row'] : [];
        $parent = $row['tx_mcp_context_parent'] ?? null;
        if (!is_scalar($parent) || (string)$parent === '') {
            return;
        }

        $parameters['items'][] = [
            'label' => 'Context ' . (string)$parent,
            'value' => 'context-' . (string)$parent,
        ];
    }
}
