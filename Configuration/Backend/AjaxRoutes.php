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
    ],
    'mcp_server_revoke_token' => [
        'path' => '/mcp-server/revoke-token',
        'target' => McpServerModuleController::class . '::revokeTokenAction',
    ],
    'mcp_server_revoke_all_tokens' => [
        'path' => '/mcp-server/revoke-all-tokens',
        'target' => McpServerModuleController::class . '::revokeAllTokensAction',
    ],
    'mcp_server_create_token' => [
        'path' => '/mcp-server/create-token',
        'target' => McpServerModuleController::class . '::createTokenAction',
    ],
];
