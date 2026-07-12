<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mcp\Server\McpServer;

$server = new McpServer('php-basics');

$server
    ->tool(
        name: 'add-numbers',
        description: 'Adds two numbers.',
        callback: static fn(float $a, float $b): array => [
            'sum' => $a + $b,
        ],
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'sum' => ['type' => 'number'],
            ],
            'required' => ['sum'],
        ],
    )
    ->run();
