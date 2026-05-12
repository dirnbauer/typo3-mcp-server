<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Set\SetDefinition;
use TYPO3\CMS\Core\Site\Set\SetRegistry;

/**
 * Find available TYPO3 Site Sets and attach/detach them from site configuration.
 *
 * Site Set assignments are stored in the site's `dependencies` YAML key and
 * are not workspace-versioned; changes take effect immediately.
 */
#[AdminOnly]
final class SiteSetTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteWriter $siteWriter,
        private readonly SetRegistry $setRegistry,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Find installed TYPO3 Site Sets and add or remove them from an existing site configuration. '
                . 'Site Sets are stored in the site YAML `dependencies` list. '
                . 'Use action "find" to discover available sets, "add" to attach one set, and "remove" to detach one set. '
                . 'Site configuration files are not workspace-versioned; add/remove changes take effect immediately. '
                . 'Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['find', 'add', 'remove'],
                        'description' => 'Action to perform: "find" lists available Site Sets, "add" attaches one Site Set to a site, "remove" detaches one Site Set from a site.',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Site identifier for add/remove, e.g. "main".',
                    ],
                    'siteSet' => [
                        'type' => 'string',
                        'description' => 'Site Set name for add/remove, e.g. "typo3/email".',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Optional find filter matched against Site Set name, label, and dependencies.',
                    ],
                    'includeHidden' => [
                        'type' => 'boolean',
                        'description' => 'For find: include hidden Site Sets. Defaults to false.',
                        'default' => false,
                    ],
                ],
                'required' => ['action'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $action = is_string($params['action'] ?? null) ? $params['action'] : '';

        return match ($action) {
            'find' => $this->handleFind($params),
            'add' => $this->handleAdd($params),
            'remove' => $this->handleRemove($params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Use "find", "add", or "remove".']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleFind(array $params): CallToolResult
    {
        $query = is_string($params['query'] ?? null) ? trim($params['query']) : '';
        $includeHidden = ($params['includeHidden'] ?? false) === true;
        $siteSets = [];

        foreach ($this->setRegistry->getAllSets() as $set) {
            if ($set->hidden && !$includeHidden) {
                continue;
            }
            if (!$this->matchesQuery($set, $query)) {
                continue;
            }
            $siteSets[] = $this->serializeSet($set);
        }

        return $this->createJsonResult([
            'status' => 'found',
            'query' => $query,
            'total' => count($siteSets),
            'siteSets' => $siteSets,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleAdd(array $params): CallToolResult
    {
        [$identifier, $siteSet] = $this->requireSiteMutationParams($params);

        $set = $this->setRegistry->getSet($siteSet);
        if (!$set instanceof SetDefinition) {
            throw new ValidationException(['Unknown site set "' . $siteSet . '". Use action "find" to list available Site Sets.']);
        }

        $config = $this->loadExistingSiteConfig($identifier);
        $dependencies = $this->normalizeDependencies($config['dependencies'] ?? []);

        if (in_array($siteSet, $dependencies, true)) {
            return $this->createJsonResult([
                'status' => 'unchanged',
                'identifier' => $identifier,
                'siteSet' => $siteSet,
                'dependencies' => $dependencies,
                'siteSetDefinition' => $this->serializeSet($set),
            ]);
        }

        $dependencies[] = $siteSet;
        $config['dependencies'] = $dependencies;
        $this->siteWriter->write($identifier, $config);

        return $this->createJsonResult([
            'status' => 'added',
            'identifier' => $identifier,
            'siteSet' => $siteSet,
            'dependencies' => $dependencies,
            'siteSetDefinition' => $this->serializeSet($set),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleRemove(array $params): CallToolResult
    {
        [$identifier, $siteSet] = $this->requireSiteMutationParams($params);

        $config = $this->loadExistingSiteConfig($identifier);
        $dependencies = $this->normalizeDependencies($config['dependencies'] ?? []);
        $filtered = array_values(array_filter(
            $dependencies,
            static fn(string $dependency): bool => $dependency !== $siteSet,
        ));

        if ($filtered === $dependencies) {
            return $this->createJsonResult([
                'status' => 'unchanged',
                'identifier' => $identifier,
                'siteSet' => $siteSet,
                'dependencies' => $dependencies,
            ]);
        }

        $config['dependencies'] = $filtered;
        $this->siteWriter->write($identifier, $config);

        return $this->createJsonResult([
            'status' => 'removed',
            'identifier' => $identifier,
            'siteSet' => $siteSet,
            'dependencies' => $filtered,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string, 1: string}
     */
    private function requireSiteMutationParams(array $params): array
    {
        $identifier = is_string($params['identifier'] ?? null) ? trim($params['identifier']) : '';
        $siteSet = is_string($params['siteSet'] ?? null) ? trim($params['siteSet']) : '';

        $this->validateIdentifier($identifier);
        if ($siteSet === '') {
            throw new ValidationException(['siteSet is required and must not be empty.']);
        }

        return [$identifier, $siteSet];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExistingSiteConfig(string $identifier): array
    {
        $sitePaths = $this->siteConfiguration->getAllSiteConfigurationPaths();
        if (!isset($sitePaths[$identifier])) {
            throw new ValidationException(['Site "' . $identifier . '" does not exist.']);
        }

        /** @var array<string, mixed> $config */
        $config = $this->siteConfiguration->load($identifier);
        return $config;
    }

    /**
     * @return list<string>
     */
    private function normalizeDependencies(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $dependencies = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '' || in_array($item, $dependencies, true)) {
                continue;
            }
            $dependencies[] = $item;
        }
        return $dependencies;
    }

    private function matchesQuery(SetDefinition $set, string $query): bool
    {
        if ($query === '') {
            return true;
        }

        $dependencies = array_values(array_filter($set->dependencies, 'is_string'));
        $optionalDependencies = array_values(array_filter($set->optionalDependencies, 'is_string'));

        $haystack = strtolower(implode(' ', [
            $set->name,
            $set->label,
            $this->localizedLabel($set->label),
            ...$dependencies,
            ...$optionalDependencies,
        ]));

        return str_contains($haystack, strtolower($query));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSet(SetDefinition $set): array
    {
        return [
            'name' => $set->name,
            'label' => $this->localizedLabel($set->label),
            'rawLabel' => $set->label,
            'dependencies' => $set->dependencies,
            'optionalDependencies' => $set->optionalDependencies,
            'hidden' => $set->hidden,
        ];
    }

    private function localizedLabel(string $label): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (is_object($languageService) && method_exists($languageService, 'sL')) {
            $localized = $languageService->sL($label);
            if (is_string($localized) && $localized !== '') {
                return $localized;
            }
        }

        return $label;
    }

    private function validateIdentifier(string $identifier): void
    {
        if ($identifier === '') {
            throw new ValidationException(['identifier is required and must not be empty.']);
        }
        if (preg_match('/^[a-zA-Z0-9-]+$/', $identifier) !== 1) {
            throw new ValidationException(['identifier must contain only alphanumeric characters and dashes.']);
        }
    }
}
