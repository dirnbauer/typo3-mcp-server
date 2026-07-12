<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use PHPUnit\Framework\Attributes\Test;

final class CapabilityManifestRuntimeConsistencyTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    #[Test]
    public function bootedToolRegistryMatchesNativeAndAllowlistedManifestInventory(): void
    {
        $this->enableDevSiteTools();
        $registry = $this->getService(ToolRegistry::class);
        $manifestService = $this->getService(CapabilityManifestService::class);
        $manifest = $manifestService->getManifest();
        $mcp = $manifest['capabilities']['x-mcp'] ?? null;
        self::assertIsArray($mcp);

        $native = $mcp['tools'] ?? null;
        $external = $mcp['external_tools'] ?? null;
        self::assertIsArray($native);
        self::assertIsArray($external);

        $registeredNames = array_keys($registry->getTools());
        $nativeNames = array_keys($native);
        $allowlistedNames = array_keys($external);
        sort($registeredNames);
        sort($nativeNames);
        sort($allowlistedNames);

        self::assertSame(
            [],
            array_values(array_diff($nativeNames, $registeredNames)),
            'Every manifest-declared native tool must exist in the booted registry.',
        );
        self::assertSame(
            [],
            array_values(array_diff($registeredNames, [...$nativeNames, ...$allowlistedNames])),
            'Every booted tool must be native or explicitly allowlisted as external.',
        );
    }
}
