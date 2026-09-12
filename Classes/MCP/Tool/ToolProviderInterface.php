<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies MCP tools that are not individual container services.
 *
 * ToolRegistry consults every `mcp.tool_provider` lazily on the first catalog
 * access instead of in its constructor, so a provider may depend on services
 * that themselves depend on the registry (the abilities bridge does: the
 * catalog abilities it projects read the registry they are listed in).
 */
#[AutoconfigureTag('mcp.tool_provider')]
interface ToolProviderInterface
{
    /**
     * @return iterable<ToolInterface>
     */
    public function getTools(): iterable;
}
