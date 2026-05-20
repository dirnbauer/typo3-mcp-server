<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\MCP\Tool\CompatibleToolAdapter;

/**
 * Gates MCP tools and resources that should only appear on local development
 * installations (DDEV, TYPO3 Development context, or localUnsafeMode=on).
 *
 * This is separate from the safety-net relaxations in LocalModeService: dev
 * tools expose site-configuration authoring, template references, and file
 * generation that are inappropriate for production MCP endpoints.
 */
final readonly class DevSiteToolService
{
    public function __construct(
        private LocalModeService $localMode,
    ) {}

    public function isAvailable(): bool
    {
        return $this->localMode->isLocalMode();
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

    private static function resolveToolClass(object $tool): string
    {
        if ($tool instanceof CompatibleToolAdapter) {
            $reflection = new \ReflectionClass($tool);
            $property = $reflection->getProperty('tool');
            $property->setAccessible(true);
            $wrapped = $property->getValue($tool);
            if (is_object($wrapped)) {
                return $wrapped::class;
            }
        }

        return $tool::class;
    }
}
