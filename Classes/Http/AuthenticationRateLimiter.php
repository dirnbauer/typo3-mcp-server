<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactoryInterface;

/** Shared, cache-backed budgets for unsuccessful HTTP authentication attempts. */
final readonly class AuthenticationRateLimiter
{
    use CorsHeadersTrait;

    public const BEARER = 'mcp-server-bearer';
    public const TOKEN = 'mcp-server-token';

    public function __construct(
        private RateLimiterFactoryInterface $rateLimiterFactory,
        private LoggerInterface $logger,
    ) {}

    /** @param callable(): ResponseInterface $handler */
    public function handle(ServerRequestInterface $request, string $scope, callable $handler): ResponseInterface
    {
        // CORS and diagnostic probes are not authentication attempts.
        $corsRejection = $this->rejectDisallowedCorsRequest($request);
        if ($corsRejection !== null) {
            return $corsRejection;
        }
        if ($request->getMethod() === 'OPTIONS'
            || ($scope === self::BEARER && ($request->getQueryParams()['test'] ?? null) === 'auth')
            || ($scope === self::TOKEN && $request->getMethod() !== 'POST')
        ) {
            return $handler();
        }

        try {
            // Core resolves the trusted proxy/client address through NormalizedParams
            // and applies SYS.rateLimiter overrides for this particular scope.
            $limiter = $this->rateLimiterFactory->createRequestBasedLimiter($request, [
                'id' => $scope,
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '15 minutes',
            ]);
            $budget = $limiter->consume(0);
            if ($budget->getRemainingTokens() <= 0) {
                return $this->limitedResponse($request, $budget->getRetryAfter()->getTimestamp());
            }

            $response = $handler();
            $failureStatuses = $scope === self::TOKEN ? [400, 401] : [401];
            if (in_array($response->getStatusCode(), $failureStatuses, true)) {
                $budget = $limiter->consume(1);
                if (!$budget->isAccepted()) {
                    return $this->limitedResponse($request, $budget->getRetryAfter()->getTimestamp());
                }
            }
            // Successful requests neither consume nor clear earlier failures.
            // Possessing one valid credential must not reset an attacker's budget.
            return $response;
        } catch (\Throwable $exception) {
            $this->logger->error('MCP authentication limiter failed', ['exception' => $exception]);
            return $this->addSecurityHeaders($this->addCorsHeaders(new JsonResponse([
                'error' => 'temporarily_unavailable',
                'error_description' => 'Authentication is temporarily unavailable.',
            ], 503), $request));
        }
    }

    private function limitedResponse(ServerRequestInterface $request, int $retryAt): ResponseInterface
    {
        return $this->addSecurityHeaders($this->addCorsHeaders(new JsonResponse([
            'error' => 'temporarily_unavailable',
            'error_description' => 'Too many unsuccessful authentication attempts. Retry later.',
        ], 429, ['Retry-After' => (string)max(1, $retryAt - time())]), $request));
    }
}
