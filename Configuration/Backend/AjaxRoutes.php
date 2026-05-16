<?php

declare(strict_types=1);

use Hn\McpServer\Controller\McpServerModuleController;

/**
 * AJAX routes configuration for MCP Server
 */
return [
    'mcp_server_get_tokens' => [
        'path' => '/mcp-server/get-tokens',
        'target' => McpServerModuleController::class . '::getUserTokensAction',
        'inheritAccessFromModule' => 'user_mcp_server',
    ],
    'mcp_server_revoke_token' => [
        'path' => '/mcp-server/revoke-token',
        'target' => McpServerModuleController::class . '::revokeTokenAction',
        'inheritAccessFromModule' => 'user_mcp_server',
    ],
    'mcp_server_revoke_all_tokens' => [
        'path' => '/mcp-server/revoke-all-tokens',
        'target' => McpServerModuleController::class . '::revokeAllTokensAction',
        'inheritAccessFromModule' => 'user_mcp_server',
    ],
    'mcp_server_create_token' => [
        'path' => '/mcp-server/create-token',
        'target' => McpServerModuleController::class . '::createTokenAction',
        'inheritAccessFromModule' => 'user_mcp_server',
    ],
];
