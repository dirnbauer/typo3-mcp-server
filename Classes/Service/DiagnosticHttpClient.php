<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Runs independent diagnostic HTTP probes in parallel.
 */
readonly class DiagnosticHttpClient
{
    private const REQUEST_TIMEOUT = 8;

    public function __construct(
        private GuzzleClientFactory $clientFactory,
        private LocalModeService $localModeService,
    ) {}

    /**
     * @param array<string, array{method: string, url: string, headers?: array<string, string>}> $requests
     * @return array<string, array{status: int, body: string}|null>
     */
    public function requestMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $client = $this->clientFactory->getClient();
        $results = array_fill_keys(array_keys($requests), null);

        $poolRequests = [];
        foreach ($requests as $id => $spec) {
            $poolRequests[$id] = new Request(
                $spec['method'],
                $spec['url'],
                $spec['headers'] ?? [],
            );
        }

        $options = $this->buildPoolOptions($requests);

        $pool = new Pool($client, $poolRequests, [
            'concurrency' => count($poolRequests),
            'options' => $options,
            'fulfilled' => function (mixed $response, string $id) use (&$results): void {
                if (!$response instanceof ResponseInterface) {
                    return;
                }

                $results[$id] = [
                    'status' => $response->getStatusCode(),
                    'body' => (string)$response->getBody(),
                ];
            },
            'rejected' => static function (): void {
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}|null
     */
    public function request(string $method, string $url, array $headers = []): ?array
    {
        $results = $this->requestMany([
            'single' => [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
            ],
        ]);

        return $results['single'] ?? null;
    }

    /**
     * @param array<string, array{method: string, url: string, headers?: array<string, string>}> $requests
     * @return array<string, mixed>
     */
    private function buildPoolOptions(array $requests): array
    {
        $options = [
            'timeout' => self::REQUEST_TIMEOUT,
            'allow_redirects' => true,
            'http_errors' => false,
        ];

        if ($this->localModeService->isLocalMode()) {
            foreach ($requests as $spec) {
                if (str_starts_with($spec['url'], 'https://')) {
                    $options['verify'] = false;
                    break;
                }
            }
        }

        return $options;
    }
}
