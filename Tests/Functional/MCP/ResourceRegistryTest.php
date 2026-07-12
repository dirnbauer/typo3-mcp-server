<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\ResourceRegistry;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Mcp\Types\CacheableResult;
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
        $uris = array_map(static fn($resource): string => (string)$resource->uri, $result->resources);

        self::assertCount(4, $result->resources);
        self::assertContains(ResourceRegistry::URI_OVERVIEW, $uris);
        self::assertContains(ResourceRegistry::URI_SKILLS_OVERVIEW, $uris);
        self::assertContains(ResourceRegistry::URI_SKILL_PREFIX . 'typo3-content-edit', $uris);
    }

    public function testReadTcaOverviewContainsPagesTable(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::URI_OVERVIEW);
        $content = $result->contents[0] ?? null;
        self::assertInstanceOf(TextResourceContents::class, $content);
        self::assertStringContainsString('`pages`', $content->text);
        self::assertSame(60_000, $result->getTtlMs());
        self::assertSame(CacheableResult::CACHE_SCOPE_PRIVATE, $result->getCacheScope());
    }

    public function testReadTcaOverviewAcceptsLegacyUri(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::LEGACY_URI_OVERVIEW);
        $content = $result->contents[0] ?? null;
        self::assertInstanceOf(TextResourceContents::class, $content);
        self::assertSame(ResourceRegistry::LEGACY_URI_OVERVIEW, $content->uri);
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

    public function testReadTcaTableAcceptsLegacyUri(): void
    {
        $result = $this->registry->readResource(ResourceRegistry::LEGACY_URI_TABLE_PREFIX . 'pages');
        $content = $result->contents[0] ?? null;
        self::assertInstanceOf(TextResourceContents::class, $content);
        self::assertSame(ResourceRegistry::LEGACY_URI_TABLE_PREFIX . 'pages', $content->uri);
        self::assertStringContainsString('TABLE SCHEMA: pages', $content->text);
    }

    public function testResourcesBlockedOutsideDevSiteMode(): void
    {
        $this->disableDevSiteTools();
        self::assertTrue($this->registry->isAvailable(), 'Static skills remain available outside local mode.');
        self::assertFalse($this->registry->isTcaAvailable());

        $listed = $this->registry->listResources();
        $uris = array_map(static fn($resource): string => (string)$resource->uri, $listed->resources);
        self::assertCount(3, $listed->resources);
        self::assertNotContains(ResourceRegistry::URI_OVERVIEW, $uris);

        $this->expectException(ValidationException::class);
        $this->registry->readResource(ResourceRegistry::URI_OVERVIEW);
    }
}
