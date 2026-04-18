<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Attribute;

/**
 * Marks a tool as admin-only.
 *
 * Enforced centrally in AbstractTool::executeInternal(): the dispatcher checks
 * $GLOBALS['BE_USER']->isAdmin() before running the tool and returns a
 * CallToolResult with isError=true if the caller is not an admin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdminOnly {}
