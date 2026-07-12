<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\ApiCore;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/** @internal Loaded only when the optional Abilities and API Core packages exist. */
final readonly class AbilitiesApiPolicyEnforcer
{
    private const API_ID = 'abilities';

    private const API_VERSION = '1';

    public function __construct(
        private ApiRegistry $apiRegistry,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function enforce(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->apiRegistry->registerApi(
            self::API_ID,
            [self::API_VERSION],
            [
                'authMode' => 'token',
                'authProviders' => ['backendbeareropaquetokenprovider'],
                'cors' => [
                    'allowedOrigins' => $this->normalizeOrigins(
                        $this->extensionConfiguration->getAbilitiesApiCorsOrigins(),
                    ),
                ],
            ],
            null,
            [
                'mcpEnabled' => false,
                'rateLimit' => [
                    'enabled' => true,
                    'limit' => 60,
                    'windowSeconds' => 60,
                    'burst' => 10,
                ],
            ],
        );
    }

    public function isEnabled(): bool
    {
        return $this->extensionConfiguration->isActivateAbilitiesApi();
    }

    #[AsEventListener(identifier: 'hn-mcp-server-abilities-api-policy-console')]
    public function enforceBeforeConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->enforce();
    }

    /**
     * @param array<int, string> $origins
     * @return list<string>
     */
    private function normalizeOrigins(array $origins): array
    {
        $normalized = [];
        foreach ($origins as $origin) {
            $origin = $this->normalizeOrigin($origin);
            if ($origin !== null) {
                $normalized[$origin] = true;
            }
        }

        return array_keys($normalized);
    }

    private function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || preg_match('/[\x00-\x20\x7f]/', $origin) === 1) {
            return null;
        }

        $parts = parse_url($origin);
        if (
            !is_array($parts)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            return null;
        }

        $scheme = strtolower(is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(is_string($parts['host'] ?? null) ? trim($parts['host'], '[]') : '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
