<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Integration\ApiCore;

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
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

final class AbilitiesApiPolicyEnforcerTest extends TestCase
{
    #[Test]
    public function mcpPolicyWinsWhenApiCoreRegistersFirst(): void
    {
        $registry = new ApiRegistry();
        $registry->registerApi(
            'abilities',
            ['1'],
            ['authMode' => 'public', 'authProviders' => [], 'cors' => ['allowedOrigins' => ['*']]],
            null,
            ['mcpEnabled' => true],
        );
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('isActivateAbilitiesApi')->willReturn(true);
        $configuration->method('getAbilitiesApiCorsOrigins')->willReturn([
            'HTTPS://Trusted.Example:443/',
            'https://other.example:8443',
            'https://invalid.example/path',
            '*',
        ]);

        (new AbilitiesApiPolicyEnforcer($registry, $configuration))->enforce();

        $this->assertSecurePolicy($registry, [
            'https://trusted.example',
            'https://other.example:8443',
        ]);
    }

    #[Test]
    public function requestMiddlewareRestoresPolicyWhenApiCoreRegistersLast(): void
    {
        $registry = new ApiRegistry();
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('isActivateAbilitiesApi')->willReturn(true);
        $configuration->method('getAbilitiesApiCorsOrigins')->willReturn([]);
        $enforcer = new AbilitiesApiPolicyEnforcer($registry, $configuration);
        $enforcer->enforce();

        // Simulate sg_apicore's activateAbilitiesApi registration running
        // after this extension and replacing the complete registry entry.
        $registry->registerApi(
            'abilities',
            ['1'],
            [
                'authMode' => 'token',
                'authProviders' => ['backendbeareropaquetokenprovider'],
            ],
        );
        self::assertTrue($registry->isMcpEnabledForApi('abilities'));
        self::assertNull($registry->getRateLimitConfig('abilities', '1'));

        $middleware = new AbilitiesApiPolicyMiddleware(
            $enforcer,
            new AbilitiesOpenApiAugmenter(new AbilitiesRegistry([])),
        );
        $handler = new class ($registry) implements RequestHandlerInterface {
            public bool $sawSecurePolicy = false;

            public function __construct(private readonly ApiRegistry $registry) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->sawSecurePolicy = !$this->registry->isMcpEnabledForApi('abilities')
                    && ($this->registry->getRateLimitConfig('abilities', '1')['limit'] ?? null) === 60;

                return new Response();
            }
        };
        $response = $middleware->process(
            new ServerRequest(new Uri('https://example.test/api/abilities/v1/abilities')),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->sawSecurePolicy);
        $this->assertSecurePolicy($registry, []);
    }

    /** @param list<string> $allowedOrigins */
    private function assertSecurePolicy(ApiRegistry $registry, array $allowedOrigins): void
    {
        self::assertSame(
            [
                'authMode' => 'token',
                'authProviders' => ['backendbeareropaquetokenprovider'],
                'cors' => ['allowedOrigins' => $allowedOrigins],
            ],
            $registry->getSecurityConfig('abilities', '1'),
        );
        self::assertSame(
            [
                'enabled' => true,
                'limit' => 60,
                'windowSeconds' => 60,
                'burst' => 10,
            ],
            $registry->getRateLimitConfig('abilities', '1'),
        );
        self::assertFalse($registry->isMcpEnabledForApi('abilities'));
    }
}
