<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Package\PackageManager;

/** Discover PSR-14 events and the listeners registered for them. */
#[DevSiteOnly]
final class ListEventsTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 25;
    private const MAXIMUM_LIMIT = 60;

    /** @var array<string, string>|null */
    private ?array $namespaceMap = null;

    public function __construct(
        private readonly ListenerProvider $listenerProvider,
        private readonly PackageManager $packageManager,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Find TYPO3 PSR-14 events and their registered listeners. Results are capped at 60; use filters to narrow them.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'event' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive event-class substring.',
                    ],
                    'listener' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive listener service, method, or identifier substring.',
                    ],
                    'withListenersOnly' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Exclude discovered event classes without listeners.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAXIMUM_LIMIT,
                        'default' => self::DEFAULT_LIMIT,
                        'description' => 'Maximum events returned; default 25, maximum 60.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                        'description' => 'Match offset for pagination.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $eventFilter = is_string($params['event'] ?? null) ? strtolower(trim($params['event'])) : '';
        $listenerFilter = is_string($params['listener'] ?? null) ? strtolower(trim($params['listener'])) : '';
        $withListenersOnly = ($params['withListenersOnly'] ?? false) === true;
        $limit = is_numeric($params['limit'] ?? null) ? (int)$params['limit'] : self::DEFAULT_LIMIT;
        $offset = is_numeric($params['offset'] ?? null) ? (int)$params['offset'] : 0;
        if ($limit < 1 || $limit > self::MAXIMUM_LIMIT) {
            throw new ValidationException([sprintf('limit must be between 1 and %d.', self::MAXIMUM_LIMIT)]);
        }
        if ($offset < 0) {
            throw new ValidationException(['offset must be zero or greater.']);
        }

        $listeners = $this->collectListeners();
        $discoveredEvents = !$withListenersOnly && $listenerFilter === '' ? $this->collectEventClasses() : [];
        $eventClasses = array_values(array_unique([...array_keys($listeners), ...$discoveredEvents]));
        sort($eventClasses, SORT_STRING);

        $events = [];
        $totalMatches = 0;
        foreach ($eventClasses as $eventClass) {
            $eventListeners = $listeners[$eventClass] ?? [];
            if ($withListenersOnly && $eventListeners === []) {
                continue;
            }
            if ($eventFilter !== '' && !str_contains(strtolower($eventClass), $eventFilter)) {
                continue;
            }
            if ($listenerFilter !== '' && !$this->matchesListener($eventListeners, $listenerFilter)) {
                continue;
            }

            ++$totalMatches;
            if ($totalMatches <= $offset || count($events) >= $limit) {
                continue;
            }
            $events[$eventClass] = array_filter([
                'listenerCount' => count($eventListeners),
                'listeners' => $eventListeners !== [] ? $eventListeners : null,
                'package' => $this->packageOfClass($eventClass),
            ], static fn(mixed $value): bool => $value !== null);
        }

        if ($totalMatches === 0 && ($eventFilter !== '' || $listenerFilter !== '')) {
            throw new ValidationException(['No PSR-14 event matches the supplied filters.']);
        }

        $nextOffset = $offset + count($events);
        $payload = [
            'eventCount' => count($events),
            'totalMatches' => $totalMatches,
            'offset' => $offset,
            'truncated' => $totalMatches > $nextOffset,
            'nextOffset' => $totalMatches > $nextOffset ? $nextOffset : null,
            'events' => $events,
            'hint' => 'Use event/listener filters or nextOffset to keep responses focused.',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }

    /** @return array<string, list<array<string, string>>> */
    private function collectListeners(): array
    {
        try {
            $definitions = $this->listenerProvider->getAllListenerDefinitions();
        } catch (\Throwable $exception) {
            throw new ValidationException([
                'Registered listeners could not be read from TYPO3: ' . $exception->getMessage(),
            ]);
        }
        $listeners = [];
        foreach ($definitions as $eventClass => $eventListeners) {
            if (!is_string($eventClass) || !is_array($eventListeners)) {
                continue;
            }
            foreach ($eventListeners as $identifier => $listener) {
                if (!is_array($listener)) {
                    continue;
                }
                $identifier = (string)$identifier;
                $service = is_string($listener['service'] ?? null) ? $listener['service'] : $identifier;
                $method = $listener['method'] ?? null;
                $listeners[$eventClass][] = array_filter([
                    'service' => $service,
                    'method' => is_string($method) ? $method : '__invoke',
                    'identifier' => $identifier !== $service ? $identifier : null,
                ], static fn(mixed $value): bool => $value !== null);
            }
        }

        return $listeners;
    }

    /** @return list<string> */
    private function collectEventClasses(): array
    {
        $events = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $classesPath = $package->getPackagePath() . 'Classes/';
            if (!is_dir($classesPath)) {
                continue;
            }
            $namespace = array_search($package->getPackageKey(), $this->namespaceMap(), true);
            if (!is_string($namespace)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($classesPath, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relativePath = substr($file->getPathname(), strlen($classesPath), -4);
                if (!str_ends_with($relativePath, 'Event')) {
                    continue;
                }
                $events[] = $namespace . str_replace('/', '\\', $relativePath);
            }
        }

        return $events;
    }

    /** @return array<string, string> */
    private function namespaceMap(): array
    {
        if ($this->namespaceMap !== null) {
            return $this->namespaceMap;
        }

        $map = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $autoload = $package->getValueFromComposerManifest('autoload');
            if (!is_object($autoload)) {
                continue;
            }
            foreach ((array)($autoload->{'psr-4'} ?? []) as $namespace => $path) {
                if (is_string($path) && str_contains($path, 'Classes/')) {
                    $map[(string)$namespace] = $package->getPackageKey();
                }
            }
        }

        return $this->namespaceMap = $map;
    }

    /** @param list<array<string, string>> $listeners */
    private function matchesListener(array $listeners, string $filter): bool
    {
        foreach ($listeners as $listener) {
            if (str_contains(strtolower(implode(' ', $listener)), $filter)) {
                return true;
            }
        }

        return false;
    }

    private function packageOfClass(string $eventClass): ?string
    {
        foreach ($this->namespaceMap() as $namespace => $packageKey) {
            if (str_starts_with($eventClass, $namespace)) {
                return $packageKey;
            }
        }

        return null;
    }
}
