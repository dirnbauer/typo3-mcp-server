<?php

declare(strict_types=1);

use Hn\McpServer\Controller\McpServerModuleController;

/**
 * Backend module configuration for MCP Server
 */
return [
    'user_mcp_server' => [
        'parent' => 'user',
        'position' => ['after' => 'user_setup'],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/user/mcp-server',
        'iconIdentifier' => 'module-mcp-server',
        'labels' => 'LLL:EXT:mcp_server/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => McpServerModuleController::class . '::mainAction',
            ],
        ],
    ],
];
