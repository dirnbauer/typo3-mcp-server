<?php

declare(strict_types=1);

use Hn\McpServer\Controller\McpServerModuleController;

/**
 * "MCP Server" in the User section: every backend user connects their own
 * clients and manages their own access tokens.
 */
return [
    'user_mcp_server' => [
        'parent' => 'user',
        'position' => ['after' => 'user_setup'],
        'access' => 'user',
        'workspaces' => '*',
        'path' => '/module/user/mcp-server',
        'iconIdentifier' => 'module-mcp-server',
        'labels' => 'mcp_server.modules.mcp_server',
        'routes' => [
            '_default' => [
                'target' => McpServerModuleController::class . '::mainAction',
            ],
        ],
    ],
];
