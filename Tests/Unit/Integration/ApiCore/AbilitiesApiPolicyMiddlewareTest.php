<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\ApiCore;

use GuzzleHttp\Psr7\ServerRequest;
use Hn\McpServer\Integration\ApiCore\AbilitiesApiPolicyEnforcer;
use Hn\McpServer\Integration\ApiCore\AbilitiesApiPolicyMiddleware;
use Hn\McpServer\Integration\ApiCore\AbilitiesOpenApiAugmenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Registry\AbstractAbility;

final class AbilitiesApiPolicyMiddlewareTest extends TestCase
{
    #[Test]
    public function disabledSettingDoesNotRegisterOrInterceptTheApi(): void
    {
        $registry = new ApiRegistry();
        $configuration = $this->configuration(false);
        $middleware = $this->middleware($registry, $configuration);
        $handler = new RecordingJsonHandler(['passed' => true]);

        $response = $middleware->process(
            new ServerRequest('GET', 'https://example.test/api/abilities/v1/auth/login'),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $handler->calls);
        self::assertFalse($registry->hasApi('abilities'));
    }

    #[Test]
    public function enabledApiAllowsOnlyAbilitiesAndDocumentationRoutes(): void
    {
        $registry = new ApiRegistry();
        $middleware = $this->middleware($registry, $this->configuration(true));
        $handler = new RecordingJsonHandler(['passed' => true]);

        foreach (['auth/login', 'auth/refresh', 'health', 'example/list', 'mcp'] as $path) {
            $response = $middleware->process(
                new ServerRequest('POST', 'https://example.test/api/abilities/v1/' . $path),
                $handler,
            );
            self::assertSame(404, $response->getStatusCode(), $path);
        }

        $allowed = $middleware->process(
            new ServerRequest('GET', 'https://example.test/api/abilities/v1/abilities'),
            $handler,
        );
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(1, $handler->calls);
        self::assertTrue($registry->hasApi('abilities'));
        self::assertFalse($registry->isMcpEnabledForApi('abilities'));
    }

    #[Test]
    public function openApiIsFilteredAndAugmentedFromTheAbilityRegistry(): void
    {
        $registry = new ApiRegistry();
        $middleware = $this->middleware($registry, $this->configuration(true));
        $handler = new RecordingJsonHandler([
            'openapi' => '3.0.3',
            'paths' => [
                '/abilities' => ['get' => ['responses' => []]],
                '/abilities/{namespace}/{name}' => ['get' => ['responses' => []]],
                '/abilities/{namespace}/{name}/run' => [
                    'post' => [
                        'responses' => ['200' => ['description' => 'Success']],
                        'security' => [['bearerAuth' => []]],
                        'parameters' => [['name' => 'namespace']],
                    ],
                ],
                '/docs.json' => ['get' => ['responses' => []]],
                '/docs/ui' => ['get' => ['responses' => []]],
                '/auth/login' => ['post' => ['responses' => []]],
                '/health' => ['get' => ['responses' => []]],
                '/mcp' => ['post' => ['responses' => []]],
            ],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http']]],
        ]);

        $response = $middleware->process(
            new ServerRequest('GET', 'https://example.test/api/abilities/v1/docs.json'),
            $handler,
        );
        $specification = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($specification);
        self::assertArrayNotHasKey('/auth/login', $specification['paths']);
        self::assertArrayNotHasKey('/health', $specification['paths']);
        self::assertArrayNotHasKey('/mcp', $specification['paths']);
        self::assertArrayHasKey('/abilities/typo3-mcp/test-contract/run', $specification['paths']);
        self::assertSame('typo3-mcp/test-contract', $specification['x-typo3-abilities'][0]['name'] ?? null);
        self::assertArrayHasKey('Typo3McpTestContractInput', $specification['components']['schemas']);
        self::assertArrayHasKey('Typo3McpTestContractOutput', $specification['components']['schemas']);
        self::assertArrayNotHasKey(
            'parameters',
            $specification['paths']['/abilities/typo3-mcp/test-contract/run']['post'],
        );
    }

    private function configuration(bool $enabled): ExtensionConfiguration
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('isActivateAbilitiesApi')->willReturn($enabled);
        $configuration->method('getAbilitiesApiCorsOrigins')->willReturn([]);
        return $configuration;
    }

    private function middleware(
        ApiRegistry $apiRegistry,
        ExtensionConfiguration $configuration,
    ): AbilitiesApiPolicyMiddleware {
        $abilities = new AbilitiesRegistry([new TestContractAbility()]);
        return new AbilitiesApiPolicyMiddleware(
            new AbilitiesApiPolicyEnforcer($apiRegistry, $configuration),
            new AbilitiesOpenApiAugmenter($abilities),
        );
    }
}

final class RecordingJsonHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        return new JsonResponse($this->payload);
    }
}

#[AsAbility(
    name: 'typo3-mcp/test-contract',
    title: 'Test contract',
    description: 'Exercises registry-derived OpenAPI contracts.',
    scopes: ['mcp:test'],
    expose: [ExecutionContext::SURFACE_REST],
)]
final class TestContractAbility extends AbstractAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['value'],
            'properties' => ['value' => ['type' => 'string']],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['echo'],
            'properties' => ['echo' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        return ['echo' => (string)($input['value'] ?? '')];
    }
}
