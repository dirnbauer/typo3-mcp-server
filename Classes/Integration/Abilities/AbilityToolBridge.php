<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\MCP\Tool\ToolProviderInterface;
use Hn\McpServer\Service\CapabilityManifestService;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;

/**
 * Projects the abilities registry into the MCP tool catalog: one AbilityTool
 * per McpProjection descriptor, i.e. per ability exposed to the "mcp"
 * surface. The MCP tool list therefore IS the registry — no compiler pass,
 * no hand-rolled tool classes.
 *
 * ToolRegistry consults the bridge lazily (see ToolProviderInterface). The
 * projection is optional so a container that does not load the abilities
 * extension (focused functional tests) still boots; production installs
 * always carry it because the package is a runtime requirement.
 */
final class AbilityToolBridge implements ToolProviderInterface
{
    public function __construct(
        private readonly ?McpProjection $projection = null,
        private readonly ?CapabilityManifestService $manifest = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->projection !== null
            && ($this->manifest === null || $this->manifest->isAbilityBridgeEnabled());
    }

    /**
     * @return list<AbilityTool>
     */
    public function getTools(): iterable
    {
        if ($this->projection === null || !$this->isAvailable()) {
            return [];
        }

        $tools = [];
        foreach ($this->projection->descriptors() as $descriptor) {
            $tools[] = new AbilityTool($descriptor, $this->projection);
        }

        return $tools;
    }
}
