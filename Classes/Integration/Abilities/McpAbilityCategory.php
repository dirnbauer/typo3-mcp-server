<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Webconsulting\Abilities\Category\AsAbilityCategory;

/**
 * Declares the category the abilities in this directory are filed under.
 *
 * The abilities named it from the start, but nothing registered it, so the
 * registry warned once per ability on every boot and the category was missing
 * from the REST and backend listings that group by it. A package that
 * contributes abilities in a category also contributes the category.
 */
#[AsAbilityCategory(
    slug: 'mcp',
    label: 'MCP',
    description: 'Inspecting and running the tools and skills this MCP server exposes.',
)]
final class McpAbilityCategory {}
