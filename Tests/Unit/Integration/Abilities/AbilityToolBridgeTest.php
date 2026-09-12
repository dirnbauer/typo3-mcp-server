<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\Abilities;

use Hn\McpServer\Integration\Abilities\AbilityTool;
use Hn\McpServer\Integration\Abilities\AbilityToolBridge;
use Hn\McpServer\MCP\Tool\ToolProviderInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures\CliOnlyAbility;
use Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures\ContextRecordingAbility;
use Hn\McpServer\Tests\Unit\Integration\Abilities\Fixtures\FailingWriteAbility;
use Mcp\Types\TextContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The bridge is the only place where the abilities registry becomes MCP
 * tools, so these tests pin the descriptor → schema mapping, the result →
 * isError mapping, and the execution context handed to the abilities
 * pipeline.
 */
final class AbilityToolBridgeTest extends TestCase
{
    private ContextRecordingAbility $echoAbility;

    private McpProjection $projection;

    /** @var list<string> */
    private array $manifestFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->echoAbility = new ContextRecordingAbility();
        $registry = new AbilitiesRegistry([
            $this->echoAbility,
            new FailingWriteAbility(),
            new CliOnlyAbility(),
        ]);
        $this->projection = new McpProjection(
            $registry,
            new AbilityExecutor(new SchemaValidator(), new PolicyProvider('/nonexistent/abilities-policy.yaml')),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        foreach ($this->manifestFiles as $file) {
            @unlink($file);
        }
        $this->manifestFiles = [];
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function bridgeProjectsOneToolPerMcpExposedAbility(): void
    {
        $tools = [...(new AbilityToolBridge($this->projection))->getTools()];

        self::assertContainsOnlyInstancesOf(AbilityTool::class, $tools);
        self::assertSame(
            ['ability_bridge-test_echo', 'ability_bridge-test_fail'],
            array_map(static fn(AbilityTool $tool): string => $tool->getName(), $tools),
        );
    }

    #[Test]
    public function bridgeIsAToolProviderTheRegistryResolvesLazily(): void
    {
        $bridge = new AbilityToolBridge($this->projection);
        self::assertInstanceOf(ToolProviderInterface::class, $bridge);

        $registry = new ToolRegistry([], null, null, [$bridge]);

        self::assertSame(
            ['ability_bridge-test_echo', 'ability_bridge-test_fail'],
            array_keys($registry->getTools()),
        );
        self::assertInstanceOf(AbilityTool::class, $registry->getTool('ability_bridge-test_echo'));
        self::assertNull($registry->getTool('ability_bridge-test_cli-only'));
    }

    #[Test]
    public function bridgeYieldsNothingWithoutTheAbilitiesProjection(): void
    {
        $bridge = new AbilityToolBridge(null);

        self::assertFalse($bridge->isAvailable());
        self::assertSame([], [...$bridge->getTools()]);
    }

    #[Test]
    public function bridgeYieldsNothingWhenTheManifestDisablesIt(): void
    {
        $bridge = new AbilityToolBridge($this->projection, $this->createManifest([
            'x-mcp' => ['integrations' => ['abilities' => ['mcp_bridge' => false]]],
        ]));

        self::assertFalse($bridge->isAvailable());
        self::assertSame([], [...$bridge->getTools()]);
    }

    #[Test]
    public function schemaMapsDescriptorDescriptionInputSchemaAndAnnotations(): void
    {
        $schema = $this->tool('ability_bridge-test_echo')->getSchema();

        self::assertSame(['description', 'inputSchema', 'annotations'], array_keys($schema));
        self::assertStringContainsString('Bridge echo — Returns the given message.', $schema['description']);
        // Registry instructions stay part of the agent-facing description.
        self::assertStringEndsWith("\n\nPass any message.", $schema['description']);
        self::assertSame('object', $schema['inputSchema']['type'] ?? null);
        self::assertSame(['message'], $schema['inputSchema']['required'] ?? null);
        self::assertSame(
            [
                'title' => 'Bridge echo',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
            $schema['annotations'],
        );
    }

    #[Test]
    public function destructiveAbilityKeepsItsTruthfulAnnotationsAndDefinition(): void
    {
        $tool = $this->tool('ability_bridge-test_fail');
        $annotations = $tool->getSchema()['annotations'];

        self::assertFalse($annotations['readOnlyHint']);
        self::assertTrue($annotations['destructiveHint']);
        self::assertFalse($annotations['idempotentHint']);
        self::assertSame('bridge-test/fail', $tool->getDefinition()->name);
        self::assertSame(['database:write'], $tool->getDefinition()->sideEffects);
    }

    #[Test]
    public function executionPassesAnMcpContextBoundToTheCurrentBackendUser(): void
    {
        $this->allowEveryTool();
        $this->setUpBackendUser(42);

        $result = $this->tool('ability_bridge-test_echo')->execute(['message' => 'hello']);

        self::assertFalse($result->isError, $this->text($result->content));
        self::assertInstanceOf(ExecutionContext::class, $this->echoAbility->lastContext);
        self::assertSame(ExecutionContext::SURFACE_MCP, $this->echoAbility->lastContext->surface);
        self::assertSame(42, $this->echoAbility->lastContext->backendUserUid);
        // MCP is a trusted surface: the endpoint authenticated the session,
        // so scope checks are skipped while policy and permission still run.
        self::assertTrue($this->echoAbility->lastContext->isTrusted());

        self::assertSame(
            ['ok' => true, 'data' => ['echo' => 'hello']],
            json_decode($this->text($result->content), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function failedAbilityResultBecomesAnMcpToolError(): void
    {
        $this->allowEveryTool();
        $this->setUpBackendUser(1);

        $result = $this->tool('ability_bridge-test_fail')->execute([]);

        self::assertTrue($result->isError);
        $payload = json_decode($this->text($result->content), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('ability_cannot_execute', $payload['errorCode']);
        self::assertStringContainsString('ability exploded', $payload['error']);
    }

    #[Test]
    public function invalidInputIsRejectedByTheAbilitiesPipelineNotTheTool(): void
    {
        $this->allowEveryTool();
        $this->setUpBackendUser(1);

        $result = $this->tool('ability_bridge-test_echo')->execute(['message' => '']);

        self::assertTrue($result->isError);
        $payload = json_decode($this->text($result->content), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ability_invalid_input', $payload['errorCode']);
        self::assertNull($this->echoAbility->lastContext, 'A schema violation must not reach the ability.');
    }

    #[Test]
    public function executionFailsClosedWithoutAnAuthenticatedBackendUser(): void
    {
        $this->allowEveryTool();
        unset($GLOBALS['BE_USER']);

        $result = $this->tool('ability_bridge-test_echo')->execute(['message' => 'hello']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('permission', strtolower($this->text($result->content)));
        self::assertNull($this->echoAbility->lastContext);
    }

    #[Test]
    public function capabilityManifestGatesAbilityToolsByTheirDeclaredSideEffects(): void
    {
        $this->setUpBackendUser(1);

        // database:write is the fixture's declared side effect and is not an
        // effective subsystem here, so the manifest refuses before execution.
        GeneralUtility::addInstance(CapabilityManifestService::class, $this->createManifest([
            'subsystems' => ['database:read'],
            'x-mcp' => ['tools' => [], 'requires' => []],
        ]));
        $denied = $this->tool('ability_bridge-test_fail')->execute([]);

        self::assertTrue($denied->isError);
        self::assertStringContainsString('permission', strtolower($this->text($denied->content)));
        self::assertStringNotContainsString('ability_cannot_execute', $this->text($denied->content));

        // Declaring it lets the very same call through to the ability, which
        // then fails on its own terms — proving the manifest was the gate.
        GeneralUtility::addInstance(CapabilityManifestService::class, $this->createManifest([
            'subsystems' => ['database:read', 'database:write'],
            'x-mcp' => ['tools' => [], 'requires' => []],
        ]));
        $allowed = $this->tool('ability_bridge-test_fail')->execute([]);

        self::assertTrue($allowed->isError);
        self::assertStringContainsString('ability_cannot_execute', $this->text($allowed->content));
    }

    #[Test]
    public function readOnlyAbilityToolNeedsNoSubsystemDeclaration(): void
    {
        $this->setUpBackendUser(7);
        GeneralUtility::addInstance(CapabilityManifestService::class, $this->createManifest([
            'subsystems' => [],
            'x-mcp' => ['tools' => [], 'requires' => []],
        ]));

        $result = $this->tool('ability_bridge-test_echo')->execute(['message' => 'ok']);

        self::assertFalse($result->isError, $this->text($result->content));
        self::assertSame(7, $this->echoAbility->lastContext?->backendUserUid);
    }

    private function tool(string $name): AbilityTool
    {
        foreach ((new AbilityToolBridge($this->projection))->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        self::fail('The bridge did not project a tool named ' . $name . '.');
    }

    /**
     * @param list<mixed> $content
     */
    private function text(array $content): string
    {
        self::assertNotEmpty($content);
        self::assertInstanceOf(TextContent::class, $content[0]);

        return $content[0]->text;
    }

    private function setUpBackendUser(int $uid): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => $uid, 'username' => 'bridge_user_' . $uid, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * AbstractTool resolves the manifest through GeneralUtility, so the
     * enforcement decision has to be seeded per execution.
     */
    private function allowEveryTool(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['enforceCapabilityManifest' => '0']);
        GeneralUtility::addInstance(
            CapabilityManifestService::class,
            new CapabilityManifestService($configuration, self::createStub(SiteFinder::class)),
        );
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private function createManifest(array $capabilities): CapabilityManifestService
    {
        $file = tempnam(sys_get_temp_dir(), 'mcp-bridge-manifest-');
        self::assertIsString($file);
        $this->manifestFiles[] = $file;
        file_put_contents($file, Yaml::dump(['capabilities' => $capabilities]));

        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new CapabilityManifestService(new ExtensionConfiguration(), $siteFinder, null, $file);
    }
}
