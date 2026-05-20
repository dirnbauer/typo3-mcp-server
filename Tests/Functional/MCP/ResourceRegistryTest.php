<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\MCP\ResourceRegistry;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;

final class ResourceRegistryTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    private ResourceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
        $this->registry = $this->getService(ResourceRegistry::class);
    }

    public function testListResourcesIncludesTcaOverview(): void
    {
        $result = $this->registry->listResources();
        self::assertCount(1, $result->resources);
        self::assertSame(ResourceRegistry::URI_OVERVIEW, $result->resources[0]->uri);
    }

    public function testReadTcaOverviewContainsPagesTable(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::URI_OVERVIEW);
        $text = $result->contents[0]->text ?? '';
        self::assertStringContainsString('`pages`', $text);
    }

    public function testReadTcaTableUsesAccessChecks(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::URI_TABLE_PREFIX . 'pages');
        $text = $result->contents[0]->text ?? '';
        self::assertStringContainsString('TABLE SCHEMA: pages', $text);
    }

    public function testResourcesBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();
        self::assertFalse($this->registry->isAvailable());

        $this->expectException(\Hn\McpServer\Exception\ValidationException::class);
        $this->registry->listResources();
    }
}
