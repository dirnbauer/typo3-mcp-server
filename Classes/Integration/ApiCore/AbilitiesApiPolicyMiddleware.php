<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\ApiCore;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * @internal Reasserts and bounds the abilities API before API Core reads it.
 *
 * sg_apicore's generic controller discovery otherwise projects unrelated
 * public auth/demo/health/MCP routes into every registered API. This layer
 * makes the abilities API an explicit allowlist and keeps the native /mcp
 * endpoint authoritative.
 */
final readonly class AbilitiesApiPolicyMiddleware implements MiddlewareInterface
{
    private const API_BASE_PATH = '/api/abilities/v1';

    public function __construct(
        private AbilitiesApiPolicyEnforcer $policyEnforcer,
        private AbilitiesOpenApiAugmenter $openApiAugmenter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->policyEnforcer->isEnabled()) {
            return $handler->handle($request);
        }

        $this->policyEnforcer->enforce();

        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path === '') {
            $path = '/';
        }
        if (!$this->isAbilitiesApiPath($path)) {
            return $handler->handle($request);
        }
        if (!$this->isAllowedAbilitiesPath($path)) {
            return new JsonResponse(
                ['error' => 'Not Found'],
                404,
                [
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'DENY',
                ],
            );
        }

        $response = $handler->handle($request);
        if ($path !== self::API_BASE_PATH . '/docs.json' || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            $specification = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $response;
        }
        if (!is_array($specification)) {
            return $response;
        }

        $normalizedSpecification = [];
        foreach ($specification as $key => $value) {
            if (is_string($key)) {
                $normalizedSpecification[$key] = $value;
            }
        }

        return new JsonResponse(
            $this->openApiAugmenter->augment($normalizedSpecification),
            200,
            $response->getHeaders(),
        );
    }

    private function isAbilitiesApiPath(string $path): bool
    {
        return $path === self::API_BASE_PATH || str_starts_with($path, self::API_BASE_PATH . '/');
    }

    private function isAllowedAbilitiesPath(string $path): bool
    {
        if ($path === self::API_BASE_PATH . '/abilities'
            || str_starts_with($path, self::API_BASE_PATH . '/abilities/')
        ) {
            return true;
        }

        return in_array($path, [
            self::API_BASE_PATH . '/docs.json',
            self::API_BASE_PATH . '/docs/ui',
        ], true);
    }
}
