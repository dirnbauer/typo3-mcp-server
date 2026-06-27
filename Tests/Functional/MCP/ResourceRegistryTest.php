<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\ResourceRegistry;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Mcp\Types\TextResourceContents;

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
        $content = $result->contents[0] ?? null;
        self::assertInstanceOf(TextResourceContents::class, $content);
        self::assertStringContainsString('`pages`', $content->text);
    }

    public function testReadTcaOverviewSerializesSpecCompliantContents(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::URI_OVERVIEW);
        $payload = json_decode((string)json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertIsArray($payload['contents'] ?? null);
        self::assertIsArray($payload['contents'][0] ?? null);
        $content = $payload['contents'][0];

        self::assertSame(
            ['uri', 'mimeType', 'text'],
            array_keys($content),
        );
        self::assertArrayNotHasKey('extraFields', $content);
    }

    public function testReadTcaTableUsesAccessChecks(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::URI_TABLE_PREFIX . 'pages');
        $content = $result->contents[0] ?? null;
        self::assertInstanceOf(TextResourceContents::class, $content);
        self::assertStringContainsString('TABLE SCHEMA: pages', $content->text);
    }

    public function testResourcesBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();
        self::assertFalse($this->registry->isAvailable());

        $this->expectException(ValidationException::class);
        $this->registry->listResources();
    }
}
