<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Attribute;

/**
 * Marks a tool as available only on development sites (DDEV, TYPO3 Development
 * context, or localUnsafeMode=on).
 *
 * Enforced centrally in AbstractTool::executeInternal() and filtered from
 * tools/list when DevSiteToolService::isAvailable() is false.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DevSiteOnly {}
