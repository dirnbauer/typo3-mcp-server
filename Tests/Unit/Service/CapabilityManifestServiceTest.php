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

    #[Test]
    public function effectiveSubsystemsIncludeFileWriteWhenAllPrerequisitesPresent(): void
    {
        $service = $this->createSubject();
        $effective = $service->getEffectiveSubsystems();

        // Default shipped manifest declares everything, so file:write is effective.
        self::assertContains('file:write', $effective);
        self::assertContains('database:write', $effective);
    }

    #[Test]
    public function fileWriteIsBlockedWhenDatabaseWriteIsRemovedFromManifest(): void
    {
        // The point of the prerequisite chain: removing `database:write`
        // from the operator's hardened manifest must also disable
        // `file:write`-dependent tools, because uploaded files only make
        // sense when there is content to attach them to.
        $service = $this->createSubjectWithManifest([
            'subsystems' => ['database:read', 'file:read', 'file:write'],
            'requires' => [
                'file:write' => ['file:read', 'database:write'],
            ],
            'tools' => [
                'UploadFileFromUrl' => ['file:write'],
            ],
        ]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessageMatches('/file:write \\(needs: database:write\\)/');
        $service->assertToolAllowed('UploadFileFromUrl');
    }

    #[Test]
    public function effectiveSubsystemsExcludeChainsWithMissingPrerequisites(): void
    {
        $service = $this->createSubjectWithManifest([
            'subsystems' => ['database:read', 'file:read', 'file:write'],
            'requires' => [
                'file:write' => ['file:read', 'database:write'],
            ],
        ]);

        $effective = $service->getEffectiveSubsystems();
        self::assertContains('database:read', $effective);
        self::assertContains('file:read', $effective);
        self::assertNotContains('file:write', $effective, 'file:write must drop out when database:write is missing');
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private function createSubjectWithManifest(array $capabilities): CapabilityManifestService
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp-cap-');
        if ($tmp === false) {
            self::fail('Could not create temp manifest file.');
        }
        file_put_contents($tmp, \Symfony\Component\Yaml\Yaml::dump(['capabilities' => $capabilities]));

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new CapabilityManifestService(new ExtensionConfiguration(), $siteFinder, $tmp);
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
