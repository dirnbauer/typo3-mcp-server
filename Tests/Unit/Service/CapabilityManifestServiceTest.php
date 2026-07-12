<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\LocalModeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
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
        self::assertContains('cli:command', $subsystems);
        // MCP-only runtime gates are merged with public coarse capabilities
        // for enforcement, but live under capabilities.x-mcp in the file.
        self::assertContains('render:frontend', $subsystems);
    }

    #[Test]
    public function exposesCommandSkillAndProtocolInventoriesFromMcpExtension(): void
    {
        $service = $this->createSubject();

        $commands = $service->getCommandDefinitions();
        self::assertSame('ReadTable', $commands['mcp:read-table']['tool'] ?? null);
        self::assertSame('resource-reader', $commands['mcp:tca-resource']['kind'] ?? null);

        $skills = $service->getSkillDefinitions();
        self::assertSame(
            'Resources/Private/Skills/typo3-content-edit/SKILL.md',
            $skills['typo3-content-edit']['source'] ?? null,
        );
        self::assertArrayHasKey('typo3-translate-page', $skills);

        $protocol = $service->getProtocolMetadata();
        self::assertSame('2025-11-25', $protocol['stable_version'] ?? null);
        self::assertContains('streamable-http', $protocol['transports'] ?? []);
    }

    #[Test]
    public function declaredToolsResolveTheirRequiredSubsystems(): void
    {
        $service = $this->createSubject();
        $required = $service->getRequiredSubsystemsForTool('ReadTable');

        self::assertSame(['database:read'], $required);
    }

    #[Test]
    public function unknownToolHasNoImplicitPolicyAndIsDenied(): void
    {
        $service = $this->createSubject();
        $required = $service->getRequiredSubsystemsForTool('CompletelyMadeUpTool');

        self::assertSame([], $required);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('not declared in capability manifest');
        $service->assertToolAllowed('CompletelyMadeUpTool');
    }

    #[Test]
    public function explicitlyDeclaredExternalToolUsesItsOwnPolicy(): void
    {
        $service = $this->createSubject();

        self::assertSame(
            ['database:read'],
            $service->getRequiredSubsystemsForTool('ability_system_site-info'),
        );
        $service->assertToolAllowed('ability_system_site-info');
    }

    #[Test]
    public function onlyExplicitFalseValuesDisableEnforcement(): void
    {
        foreach ([false, 0, '0', 'false', 'no', 'off'] as $value) {
            self::assertFalse($this->createSubject(['enforceCapabilityManifest' => $value])->isEnforced());
        }

        foreach ([true, 1, 2, '1', 'true', 'yes', 'on', 'unexpected', ''] as $value) {
            self::assertTrue($this->createSubject(['enforceCapabilityManifest' => $value])->isEnforced());
        }
    }

    #[Test]
    public function assertHostAllowedRejectsUnknownHostByDefaultManifest(): void
    {
        $service = $this->createSubject();

        $this->expectException(AccessDeniedException::class);
        $service->assertHostAllowed('evil.example.org');
    }

    #[Test]
    public function structuredOutboundDeclarationsAreNormalizedForRuntimeEnforcement(): void
    {
        $service = $this->createSubjectWithManifest([
            'subsystems' => [],
            'network' => [
                'outbound' => [
                    ['host' => 'api.example.org', 'purpose' => 'Test API', 'protocol' => 'https'],
                    ['host' => '*.assets.example.org', 'purpose' => 'Test CDN', 'protocol' => 'https'],
                ],
            ],
        ]);

        self::assertSame(['api.example.org', '*.assets.example.org'], $service->getNetworkOutboundPolicy());
        $service->assertHostAllowed('api.example.org');
        $service->assertHostAllowed('images.assets.example.org');
        $service->assertUrlAllowed('https://api.example.org/data');

        try {
            $service->assertUrlAllowed('http://api.example.org/data');
            self::fail('The structured HTTPS-only rule must reject plaintext HTTP.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
    }

    #[Test]
    public function schemaAnyOutboundValueIsNormalizedToRuntimeWildcard(): void
    {
        $service = $this->createSubjectWithManifest([
            'subsystems' => [],
            'network' => ['outbound' => 'any'],
        ]);

        self::assertSame(['*'], $service->getNetworkOutboundPolicy());
        $service->assertHostAllowed('api.example.org');
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
    public function assertHostAllowedSkipsWhenLocalModeIsOn(): void
    {
        // DDEV / localUnsafeMode=on bypasses the manifest's outbound gate
        // so workflows like "fetch this Unsplash image into fileadmin" work
        // in dev without operators editing Capabilities.yaml. The
        // SSRF private-IP filter inside UploadFileFromUrl is also lifted
        // in this mode (see UploadFileFromUrlTool::validateUrl).
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $service = new CapabilityManifestService(
            new ExtensionConfiguration(),
            $siteFinder,
            new LocalModeService(new ExtensionConfiguration()),
        );

        $service->assertHostAllowed('images.unsplash.com');
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

    #[Test]
    public function mcpExtensionPolicyTakesPrecedenceOverLegacyTopLevelPolicy(): void
    {
        $service = $this->createSubjectWithManifest([
            'subsystems' => ['database:read'],
            'tools' => ['ReadTable' => ['database:write']],
            'requires' => ['database:read' => ['database:write']],
            'x-mcp' => [
                'runtime_subsystems' => ['workspace:read'],
                'tools' => ['ReadTable' => ['database:read']],
                'requires' => [],
            ],
        ]);

        self::assertSame(['database:read'], $service->getRequiredSubsystemsForTool('ReadTable'));
        self::assertSame([], $service->getRequiresMap());
        self::assertContains('workspace:read', $service->getDeclaredSubsystems());
        $service->assertToolAllowed('ReadTable');
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
        file_put_contents($tmp, Yaml::dump(['capabilities' => $capabilities]));

        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new CapabilityManifestService(
            new ExtensionConfiguration(),
            $siteFinder,
            $this->createLocalModeOff(),
            $tmp,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSubject(array $config = []): CapabilityManifestService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $config;
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        return new CapabilityManifestService(
            new ExtensionConfiguration(),
            $siteFinder,
            $this->createLocalModeOff(),
        );
    }

    /**
     * Force local mode OFF so the manifest's outbound-host gate stays
     * meaningful during the assert*Allowed tests. Production-default
     * behavior is `auto`; pinning here keeps the unit test independent
     * of the test runner's env (DDEV / Development context).
     */
    private function createLocalModeOff(): LocalModeService
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'off';
        return new LocalModeService(new ExtensionConfiguration());
    }
}
