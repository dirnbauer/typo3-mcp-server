<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\CapabilityManifestService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

final class CapabilityManifestServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        parent::tearDown();
    }

    #[Test]
    public function loadsShippedManifestAndDeclaresExpectedSubsystems(): void
    {
        $service = $this->createSubject();
        $subsystems = $service->getDeclaredSubsystems();

        self::assertContains('database:read', $subsystems);
        self::assertContains('database:write', $subsystems);
        self::assertContains('file:write', $subsystems);
        self::assertContains('render:frontend', $subsystems);
    }

    #[Test]
    public function declaredToolsResolveTheirRequiredSubsystems(): void
    {
        $service = $this->createSubject();
        $required = $service->getRequiredSubsystemsForTool('ReadTable');

        self::assertSame(['database:read'], $required);
    }

    #[Test]
    public function unknownToolFailsClosedToReadAndWrite(): void
    {
        $service = $this->createSubject();
        $required = $service->getRequiredSubsystemsForTool('CompletelyMadeUpTool');

        self::assertSame(['database:read', 'database:write'], $required);
    }

    #[Test]
    public function assertHostAllowedRejectsUnknownHostByDefaultManifest(): void
    {
        $service = $this->createSubject();

        $this->expectException(AccessDeniedException::class);
        $service->assertHostAllowed('evil.example.org');
    }

    #[Test]
    public function assertHostAllowedSkipsWhenEnforcementDisabled(): void
    {
        $service = $this->createSubject(['enforceCapabilityManifest' => '0']);
        $service->assertHostAllowed('evil.example.org');

        // No exception means we passed.
        self::assertTrue(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config = []): CapabilityManifestService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $config;
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        return new CapabilityManifestService(new ExtensionConfiguration(), $siteFinder);
    }
}
