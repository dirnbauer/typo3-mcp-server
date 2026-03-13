<?php

declare(strict_types=1);

use Hn\McpServer\Command\McpServerCommand;
use Hn\McpServer\Command\McpTestCommand;
use Hn\McpServer\Command\OAuthManageCommand;

return [
    'mcp:server' => [
        'class' => McpServerCommand::class,
        'schedulable' => false,
    ],
    'mcp:test' => [
        'class' => McpTestCommand::class,
        'schedulable' => false,
    ],
    'mcp:oauth' => [
        'class' => OAuthManageCommand::class,
        'schedulable' => false,
    ],
];
