<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;

/**
 * Gates MCP tools and resources that should only appear when
 * {@see LocalModeService::isLocalMode()} is active (DDEV, TYPO3 Development
 * context, or explicit localUnsafeMode=on).
 *
 * Dev-site tools share the same master toggle as live workspace writes and
 * unrestricted file access. {@see LocalModeService::enforcesStrictSandbox()}
 * disables all three relaxations even inside DDEV.
 */
final readonly class DevSiteToolService
{
    public function __construct(
        private LocalModeService $localMode,
    ) {}

    public function isAvailable(): bool
    {
        return $this->localMode->allowsDevTools();
    }

    /**
     * @throws ValidationException
     */
    public function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new ValidationException([
                'This feature is only available in DDEV / local development mode. '
                . 'Enable localUnsafeMode=on or run inside DDEV / TYPO3 Development context.',
            ]);
        }
    }

    /**
     * @return array{available: bool, hint: string}
     */
    public function describe(): array
    {
        return [
            'available' => $this->isAvailable(),
            'hint' => 'Requires DDEV, TYPO3 Development context, or localUnsafeMode=on',
        ];
    }

    public static function hasDevSiteOnlyAttribute(object $tool): bool
    {
        $class = self::resolveToolClass($tool);

        return (new \ReflectionClass($class))->getAttributes(DevSiteOnly::class) !== [];
    }

    /**
     * @return class-string
     */
    private static function resolveToolClass(object $tool): string
    {
        if ($tool instanceof CompatibleToolAdapter) {
            $reflection = new \ReflectionClass($tool);
            $property = $reflection->getProperty('tool');
            $wrapped = $property->getValue($tool);
            if (is_object($wrapped)) {
                return $wrapped::class;
            }
        }

        return $tool::class;
    }
}
