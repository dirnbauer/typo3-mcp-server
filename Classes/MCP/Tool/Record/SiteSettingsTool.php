<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Settings\SettingsFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Read and update TYPO3 site settings (values in per-site settings.yaml files)
 * with validation against Site Set setting definitions.
 */
#[AdminOnly]
#[DevSiteOnly]
final class SiteSettingsTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly SiteFinder $siteFinder,
        private readonly SiteWriter $siteWriter,
        private readonly SetRegistry $setRegistry,
        private readonly SiteSettingsFactory $siteSettingsFactory,
        private readonly SettingsFactory $settingsFactory,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'List Site Set setting definitions and read or update site setting values in settings.yaml. '
                . 'Values are validated against setting types and enums from attached Site Sets. '
                . 'Site settings are not workspace-versioned; updates take effect immediately. '
                . 'Dev-site only (DDEV / localUnsafeMode). Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['listDefinitions', 'get', 'update'],
                        'description' => 'listDefinitions: schema from Site Sets; get: current values; update: merge validated values into settings.yaml.',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Site identifier, e.g. "main". Required for all actions.',
                    ],
                    'settings' => [
                        'type' => 'object',
                        'description' => 'Setting key/value map for action "update". Only defined, non-readonly keys are accepted.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['action', 'identifier'],
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
        $identifier = is_string($params['identifier'] ?? null) ? trim($params['identifier']) : '';

        if ($identifier === '') {
            throw new ValidationException(['identifier is required.']);
        }

        $site = $this->resolveSite($identifier);

        return match ($action) {
            'listDefinitions' => $this->handleListDefinitions($site),
            'get' => $this->handleGet($site),
            'update' => $this->handleUpdate($site, $params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Use listDefinitions, get, or update.']),
        };
    }

    private function resolveSite(string $identifier): Site
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (SiteNotFoundException) {
            throw new ValidationException(['Site "' . $identifier . '" does not exist.']);
        }
    }

    private function handleListDefinitions(Site $site): CallToolResult
    {
        $definitions = $this->collectDefinitions($site);
        $categories = [];
        foreach ($this->setRegistry->getSets(...$site->getSets()) as $set) {
            foreach ($set->categoryDefinitions as $category) {
                $categories[$category->key] = [
                    'key' => $category->key,
                    'label' => $category->label,
                    'description' => $category->description,
                    'parent' => $category->parent,
                ];
            }
        }

        return $this->createJsonResult([
            'status' => 'ok',
            'identifier' => $site->getIdentifier(),
            'sets' => $site->getSets(),
            'categories' => array_values($categories),
            'definitions' => array_map(
                static fn(SettingDefinition $definition): array => json_decode(
                    json_encode($definition, JSON_THROW_ON_ERROR),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                array_values($definitions),
            ),
            'total' => count($definitions),
        ]);
    }

    private function handleGet(Site $site): CallToolResult
    {
        $definitions = $this->collectDefinitions($site);
        $settings = $this->siteSettingsFactory->getSettings(
            $site->getIdentifier(),
            $site->getConfiguration(),
        );
        $localSettings = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];

        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = [
                'value' => $settings->has($key) ? $settings->get($key) : $definition->default,
                'default' => $definition->default,
                'localOverride' => array_key_exists($key, $localSettings),
                'readonly' => $definition->readonly,
                'type' => $definition->type,
            ];
        }

        return $this->createJsonResult([
            'status' => 'ok',
            'identifier' => $site->getIdentifier(),
            'settings' => $values,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleUpdate(Site $site, array $params): CallToolResult
    {
        if (!isset($params['settings']) || !is_array($params['settings']) || $params['settings'] === []) {
            throw new ValidationException(['settings object is required for action "update".']);
        }

        $definitions = $this->collectDefinitions($site);
        $localSettings = $this->siteSettingsFactory->loadLocalSettings($site->getIdentifier()) ?? [];
        $incoming = [];

        foreach ($params['settings'] as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (!isset($definitions[$key])) {
                throw new ValidationException(['Unknown setting key "' . $key . '".']);
            }
            if ($definitions[$key]->readonly) {
                throw new ValidationException(['Setting "' . $key . '" is readonly.']);
            }
            $incoming[$key] = $value;
        }

        try {
            $validated = $this->settingsFactory->createSettingsFromFormData($incoming, $definitions);
        } catch (\RuntimeException $exception) {
            throw new ValidationException([$exception->getMessage()]);
        }

        foreach ($incoming as $key => $value) {
            if ($validated->has($key)) {
                $localSettings[$key] = $validated->get($key);
            }
        }

        $this->siteWriter->writeSettings($site->getIdentifier(), $localSettings);
        $this->flushSiteCache();

        return $this->createJsonResult([
            'status' => 'updated',
            'identifier' => $site->getIdentifier(),
            'applied' => array_keys($incoming),
            'settings' => $localSettings,
        ]);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function collectDefinitions(Site $site): array
    {
        $definitions = [];
        foreach ($this->setRegistry->getSets(...$site->getSets()) as $set) {
            foreach ($set->settingsDefinitions as $definition) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    private function flushSiteCache(): void
    {
        try {
            GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
        } catch (\Throwable) {
            // Best effort for dev sites.
        }
    }
}
