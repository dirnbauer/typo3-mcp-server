<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\AuthenticationRateLimiter;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactory;

final class AuthenticationRateLimiterTest extends AbstractFunctionalTest
{
    private mixed $previousRateLimitConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRateLimitConfiguration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'] ?? [];
        foreach ([AuthenticationRateLimiter::BEARER, AuthenticationRateLimiter::TOKEN] as $scope) {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][$scope] = ['limit' => 2];
        }
        $this->getContainer()->get(CacheManager::class)->getCache('ratelimiter')->flush();
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'] = $this->previousRateLimitConfiguration;
        parent::tearDown();
    }

    public function testFailuresAreSharedAcrossInstancesAndThrottlingSkipsAuthentication(): void
    {
        $request = $this->request()->withHeader('Origin', 'https://example.com');
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $this->limiter()->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401));
            self::assertSame(401, $response->getStatusCode());
        }
        $response = $this->limiter()->handle($request, AuthenticationRateLimiter::BEARER, static function (): never {
            self::fail('An exhausted budget must be checked before authenticating.');
        });

        self::assertSame(429, $response->getStatusCode());
        self::assertGreaterThan(0, (int)$response->getHeaderLine('Retry-After'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Retry-After', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertSame('temporarily_unavailable', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testSuccessfulRequestsDoNotConsumeOrResetTheFailureBudget(): void
    {
        $limiter = $this->limiter();
        $request = $this->request();
        $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401));
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            self::assertSame(200, $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([]))->getStatusCode());
        }
        self::assertSame(401, $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401))->getStatusCode());
        self::assertSame(429, $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([]))->getStatusCode());
    }

    public function testIpAndEndpointBudgetsAreIndependentAndUntrustedForwardedHeadersDoNotBypassThem(): void
    {
        $limiter = $this->limiter();
        $request = $this->request();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401));
        }
        self::assertSame(429, $limiter->handle($this->request('198.51.100.1', '198.51.100.99'), AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([]))->getStatusCode());
        self::assertSame(401, $limiter->handle($this->request('198.51.100.2'), AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401))->getStatusCode());
        self::assertSame(400, $limiter->handle($request, AuthenticationRateLimiter::TOKEN, static fn() => new JsonResponse([], 400))->getStatusCode());
    }

    public function testProtocolErrorsAndPreflightsDoNotUseAuthenticationBudget(): void
    {
        $limiter = $this->limiter();
        $request = $this->request();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            self::assertSame(400, $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 400))->getStatusCode());
            self::assertSame(200, $limiter->handle($request->withMethod('OPTIONS'), AuthenticationRateLimiter::TOKEN, static fn() => new JsonResponse([]))->getStatusCode());
        }
        self::assertSame(401, $limiter->handle($request, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401))->getStatusCode());
        self::assertSame(400, $limiter->handle($request, AuthenticationRateLimiter::TOKEN, static fn() => new JsonResponse([], 400))->getStatusCode());
    }

    public function testTrustedProxyClientsHaveSeparateBudgets(): void
    {
        $previousProxy = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] ?? '';
        $previousHeaderMode = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] ?? '';
        try {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = '192.0.2.10';
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = 'first';
            $limiter = $this->limiter();
            $firstClient = $this->request('192.0.2.10', '198.51.100.1');
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                self::assertSame(401, $limiter->handle($firstClient, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401))->getStatusCode());
            }
            self::assertSame(429, $limiter->handle($firstClient, AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([]))->getStatusCode());
            self::assertSame(401, $limiter->handle($this->request('192.0.2.10', '198.51.100.2'), AuthenticationRateLimiter::BEARER, static fn() => new JsonResponse([], 401))->getStatusCode());
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = $previousProxy;
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = $previousHeaderMode;
        }
    }

    public function testInvalidLimiterConfigurationFailsClosedWithoutLeakingDetails(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][AuthenticationRateLimiter::BEARER]['policy'] = 'invalid-policy';
        $executed = false;
        $response = $this->limiter()->handle($this->request(), AuthenticationRateLimiter::BEARER, static function () use (&$executed): JsonResponse {
            $executed = true;
            return new JsonResponse([]);
        });
        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($executed, 'Broken limiter configuration must not disable authentication protection.');
        self::assertStringNotContainsString('invalid-policy', (string)$response->getBody());
    }

    private function limiter(): AuthenticationRateLimiter
    {
        return new AuthenticationRateLimiter($this->getContainer()->get(RateLimiterFactory::class), new NullLogger());
    }

    private function request(string $ip = '198.51.100.1', string $forwardedFor = ''): ServerRequest
    {
        return new ServerRequest('https://example.com/mcp', 'POST', serverParams: [
            'REMOTE_ADDR' => $ip,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);
    }
}
