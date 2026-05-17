<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for creating and updating TYPO3 site configurations.
 *
 * Supports four actions:
 * - create: Create a new site configuration with root page, base URL, and languages.
 * - update: Merge arbitrary top-level keys (dependencies, sets, settings, routes, ...) into an existing site config.
 * - addLanguage: Add a language to an existing site configuration.
 * - replaceLanguages: Replace the full language list of an existing site while preserving
 *   unrelated site configuration keys such as route enhancers and settings.
 *
 * Admin-only: only backend admin users may create or modify site configurations.
 * Site configurations are YAML-based and not workspace-versioned; changes take effect immediately.
 */
#[AdminOnly]
final class CreateSiteTool extends AbstractRecordTool
{
    private const GLOBAL_TYPOSCRIPT_INCLUDE = <<<'TYPOSCRIPT'
page = PAGE
page.10 = CONTENT
page.10 {
  table = tt_content
  select {
    orderBy = sorting
    where = {#colPos}=0
  }
}

TYPOSCRIPT;

    /**
     * Common ISO 639-1 codes mapped to TYPO3 flag identifiers.
     *
     * @var array<string, string>
     */
    private const FLAG_MAP = [
        'de' => 'de',
        'fr' => 'fr',
        'es' => 'es',
        'it' => 'it',
        'nl' => 'nl',
        'pl' => 'pl',
        'pt' => 'pt',
        'da' => 'dk',
        'sv' => 'se',
        'no' => 'no',
        'fi' => 'fi',
        'cs' => 'cz',
        'sk' => 'sk',
        'hu' => 'hu',
        'ro' => 'ro',
        'bg' => 'bg',
        'hr' => 'hr',
        'sl' => 'si',
        'el' => 'gr',
        'tr' => 'tr',
        'ru' => 'ru',
        'uk' => 'ua',
        'ja' => 'jp',
        'zh' => 'cn',
        'ko' => 'kr',
        'ar' => 'sa',
        'en' => 'us',
    ];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteWriter $siteWriter,
        private readonly LanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
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
            'description' => 'Create or update a TYPO3 site configuration. '
                . 'Action "create" builds a new site config with root page, base URL, optional languages, and optional rendering definitions (dependencies/sets). '
                . 'Pass rootPageId to use an existing root page, or pass parentPageId + rootPageTitle to create the site root page below an existing visible page. '
                . 'Do not create a new website by creating a "Home" page at pid=0 with WriteTable; use this tool instead. '
                . 'Action "update" merges arbitrary top-level keys (dependencies, sets, settings, routes, routeEnhancers, ...) into an existing site config while preserving unrelated keys. Use this to attach a Site Set theme (e.g. "webconsulting/desiderio-preset-corporate") to an existing site. '
                . 'Action "addLanguage" adds a language to an existing site. '
                . 'Action "replaceLanguages" replaces the full language list of an existing site while preserving other site settings. '
                . 'Site configurations are YAML-based and take effect immediately (not workspace-versioned). '
                . 'IMPORTANT: A site without a Site Set or TypoScript template record will throw "No site configuration or TypoScript template record found" in the frontend. '
                . 'Pass `dependencies` (array of Site Set names) when creating a site to attach a theme, or use action "update" afterwards. '
                . 'When no dependencies/sys_template/theme-like Site Set exists, create writes a minimal site-level setup.typoscript fallback in the active TYPO3 site configuration path. '
                . 'Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create" for a new site, "update" to merge top-level keys into an existing site, "addLanguage" to append one language, or "replaceLanguages" to replace the full language list.',
                        'enum' => ['create', 'update', 'addLanguage', 'replaceLanguages'],
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Site identifier (alphanumeric and dashes only, e.g. "main", "my-site").',
                    ],
                    'rootPageId' => [
                        'type' => 'integer',
                        'description' => 'Existing root page UID for the site (create action only). If omitted, parentPageId is required so CreateSite can create the root page below an existing visible page.',
                    ],
                    'parentPageId' => [
                        'type' => 'integer',
                        'description' => 'Create action only: parent page UID under which a new site root page should be created when rootPageId is omitted. Must be a positive page UID; pid=0 is rejected for this website creation flow.',
                    ],
                    'rootPageTitle' => [
                        'type' => 'string',
                        'description' => 'Create action only: title for the new site root page when parentPageId is used. Defaults to a title derived from identifier.',
                    ],
                    'rootPageSlug' => [
                        'type' => 'string',
                        'description' => 'Create action only: slug for the new site root page when parentPageId is used. Defaults to "/" plus the identifier.',
                    ],
                    'base' => [
                        'type' => 'string',
                        'description' => 'Base URL of the site, e.g. "https://example.com/" (create action only).',
                    ],
                    'dependencies' => [
                        'type' => 'array',
                        'description' => 'Optional: Site Set names to attach (create + update). '
                            . 'Example: ["webconsulting/desiderio-preset-corporate"]. '
                            . 'Without at least one Site Set (or a sys_template), create may add a minimal site-level TypoScript fallback.',
                        'items' => ['type' => 'string'],
                    ],
                    'sets' => [
                        'type' => 'array',
                        'description' => 'Optional: Alias for dependencies (some templates expect "sets"). Merged with dependencies. Items must be strings.',
                        'items' => ['type' => 'string'],
                    ],
                    'settings' => [
                        'type' => 'object',
                        'description' => 'Optional: top-level "settings" dictionary merged into site config (create + update).',
                        'additionalProperties' => true,
                    ],
                    'config' => [
                        'type' => 'object',
                        'description' => 'Optional (update action only): arbitrary top-level keys to merge into the site YAML (e.g. routeEnhancers, errorHandling). Existing keys are replaced, unknown keys are preserved.',
                        'additionalProperties' => true,
                    ],
                    'defaultLanguage' => [
                        'type' => 'object',
                        'description' => 'Default language configuration. Defaults to English (en_US.UTF-8) if omitted for create. Required for replaceLanguages. '
                            . 'If flag is omitted, TYPO3 flag defaults are derived from iso-639-1 (for example en -> us, de -> de).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Language title, e.g. "English".'],
                            'navigationTitle' => ['type' => 'string', 'description' => 'Optional navigation label shown in TYPO3, e.g. "Deutsch".'],
                            'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "en_US.UTF-8".'],
                            'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "en".'],
                            'flag' => ['type' => 'string', 'description' => 'Optional TYPO3 flag identifier. Defaults from iso-639-1, e.g. "us" or "de".'],
                        ],
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'Additional languages to add after the default language. Used by create and replaceLanguages.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Language title, e.g. "German".'],
                                'navigationTitle' => ['type' => 'string', 'description' => 'Optional navigation label shown in TYPO3, e.g. "Deutsch".'],
                                'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "de_DE.UTF-8".'],
                                'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "de".'],
                                'flag' => ['type' => 'string', 'description' => 'Optional TYPO3 flag identifier. Defaults from iso-639-1, e.g. "de".'],
                                'base' => ['type' => 'string', 'description' => 'Language base path, e.g. "/de/".'],
                                'fallbackType' => ['type' => 'string', 'description' => 'Fallback type: "strict", "fallback", or "free". Default: "fallback".'],
                            ],
                            'required' => ['title', 'locale', 'iso-639-1'],
                        ],
                    ],
                    'language' => [
                        'type' => 'object',
                        'description' => 'Language to add (addLanguage action only).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Language title, e.g. "French".'],
                            'navigationTitle' => ['type' => 'string', 'description' => 'Optional navigation label shown in TYPO3, e.g. "Français".'],
                            'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "fr_FR.UTF-8".'],
                            'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "fr".'],
                            'flag' => ['type' => 'string', 'description' => 'Optional TYPO3 flag identifier. Defaults from iso-639-1, e.g. "fr".'],
                            'base' => ['type' => 'string', 'description' => 'Language base path, e.g. "/fr/".'],
                            'fallbackType' => ['type' => 'string', 'description' => 'Fallback type: "strict", "fallback", or "free". Default: "fallback".'],
                        ],
                        'required' => ['title', 'locale', 'iso-639-1'],
                    ],
                ],
                'required' => ['action', 'identifier'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
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
        $identifier = is_string($params['identifier'] ?? null) ? $params['identifier'] : '';

        $this->validateIdentifier($identifier);

        return match ($action) {
            'create' => $this->handleCreate($identifier, $params),
            'update' => $this->handleUpdate($identifier, $params),
            'addLanguage' => $this->handleAddLanguage($identifier, $params),
            'replaceLanguages' => $this->handleReplaceLanguages($identifier, $params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Use "create", "update", "addLanguage", or "replaceLanguages".']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleCreate(string $identifier, array $params): CallToolResult
    {
        $rootPageId = is_numeric($params['rootPageId'] ?? null) ? (int)$params['rootPageId'] : 0;
        $base = is_string($params['base'] ?? null) ? trim($params['base']) : '';
        $rootPage = null;

        $errors = [];
        if ($rootPageId <= 0 && !array_key_exists('parentPageId', $params)) {
            $errors[] = 'Either rootPageId must be a positive existing root page UID, or parentPageId must be a positive existing page UID so CreateSite can create the site root page below it.';
        }
        if ($base === '') {
            $errors[] = 'base is required and must be a non-empty string.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($rootPageId <= 0) {
            $rootPage = $this->createSiteRootPage($identifier, $params);
            $rootPageId = $rootPage['uid'];
        } else {
            // Validate root page exists
            $page = BackendUtility::getRecord('pages', $rootPageId, 'uid,title');
            if ($page === null) {
                throw new ValidationException(['Root page with UID ' . $rootPageId . ' does not exist.']);
            }
        }

        $defaultLangParams = is_array($params['defaultLanguage'] ?? null) ? $params['defaultLanguage'] : [];
        $config = [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => $this->buildLanguageSet(
                $defaultLangParams !== [] ? $defaultLangParams : [
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                ],
                is_array($params['languages'] ?? null) ? $params['languages'] : [],
            ),
        ];

        $this->applyRenderingAndSettings($config, $params);

        $this->siteWriter->write($identifier, $config);
        $this->languageService->reset();
        $renderingFallback = $this->ensureRenderingFallback($config, $identifier);

        $response = [
            'status' => 'created',
            'identifier' => $identifier,
            'config' => $config,
        ];
        if ($rootPage !== null) {
            $response['rootPage'] = $rootPage;
        }
        if ($renderingFallback !== null) {
            $response['renderingFallback'] = $renderingFallback;
        }

        $warning = $this->renderingWarningFor($config, $identifier);
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return $this->createJsonResult($response);
    }

    /**
     * Create a site root page below an existing visible page. This intentionally
     * rejects pid=0 so "create a website" does not create a top-level Home page
     * outside the editor's mounted site tree.
     *
     * @param array<string, mixed> $params
     * @return array{uid: int, parentPageId: int, title: string, slug: string}
     */
    private function createSiteRootPage(string $identifier, array $params): array
    {
        $parentPageId = is_numeric($params['parentPageId'] ?? null) ? (int)$params['parentPageId'] : 0;
        if ($parentPageId <= 0) {
            throw new ValidationException([
                'parentPageId must be a positive existing page UID when CreateSite creates a new root page. '
                . 'Creating a website root page at pid=0 is intentionally rejected for this flow.',
            ]);
        }

        $parentPage = BackendUtility::getRecord('pages', $parentPageId, 'uid,title');
        if ($parentPage === null) {
            throw new ValidationException(['Parent page with UID ' . $parentPageId . ' does not exist.']);
        }

        $this->ensureTableAccess('pages', 'write');

        $title = is_string($params['rootPageTitle'] ?? null) && trim($params['rootPageTitle']) !== ''
            ? trim($params['rootPageTitle'])
            : $this->titleFromIdentifier($identifier);
        $slug = is_string($params['rootPageSlug'] ?? null) && trim($params['rootPageSlug']) !== ''
            ? trim($params['rootPageSlug'])
            : '/' . strtolower($identifier);
        $slug = $this->normalizePageSlug($slug);

        $newId = 'NEW' . bin2hex(random_bytes(8));
        $pageData = [
            'pid' => $parentPageId,
            'title' => $title,
            'slug' => $slug,
            'doktype' => 1,
            'hidden' => 0,
        ];
        if ($this->hasPageColumn('is_siteroot')) {
            $pageData['is_siteroot'] = 1;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new ValidationException(['Could not create site root page: no backend user is available.']);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start(['pages' => [$newId => $pageData]], []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new ValidationException([
                'Could not create site root page: ' . implode(', ', array_map(
                    static fn(mixed $error): string => is_scalar($error) ? (string)$error : get_debug_type($error),
                    $dataHandler->errorLog,
                )),
            ]);
        }

        $newPageUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
        if (!is_numeric($newPageUid) || (int)$newPageUid <= 0) {
            throw new ValidationException(['Could not create site root page: DataHandler did not return a page UID.']);
        }

        return [
            'uid' => (int)$newPageUid,
            'parentPageId' => $parentPageId,
            'title' => $title,
            'slug' => $slug,
        ];
    }

    /**
     * Merge arbitrary top-level keys into an existing site configuration.
     *
     * Preserves unrelated keys. Accepts dependencies/sets/settings shortcuts and a
     * generic `config` object for anything else (routeEnhancers, errorHandling, ...).
     *
     * @param array<string, mixed> $params
     */
    private function handleUpdate(string $identifier, array $params): CallToolResult
    {
        $config = $this->loadExistingSiteConfig($identifier);

        $applied = $this->applyRenderingAndSettings($config, $params);

        if (isset($params['config']) && is_array($params['config'])) {
            foreach ($params['config'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                // Protect structural keys — changing rootPageId/base/languages must go through create/addLanguage/replaceLanguages
                if (in_array($key, ['rootPageId', 'base', 'languages'], true)) {
                    continue;
                }
                $config[$key] = $value;
                $applied[] = $key;
            }
        }

        if ($applied === []) {
            throw new ValidationException([
                'update action requires at least one of: dependencies, sets, settings, or config.',
            ]);
        }

        $this->siteWriter->write($identifier, $config);
        $this->languageService->reset();

        $response = [
            'status' => 'updated',
            'identifier' => $identifier,
            'appliedKeys' => array_values(array_unique($applied)),
            'config' => $config,
        ];

        $warning = $this->renderingWarningFor($config, $identifier);
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return $this->createJsonResult($response);
    }

    /**
     * Apply the dependencies / sets / settings shortcuts into a site config in place.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $params
     * @return list<string> list of config keys that were touched
     */
    private function applyRenderingAndSettings(array &$config, array $params): array
    {
        $applied = [];

        $dependencies = $this->normalizeStringList($params['dependencies'] ?? null);
        $sets = $this->normalizeStringList($params['sets'] ?? null);
        $combined = array_values(array_unique(array_merge($dependencies, $sets)));

        if ($combined !== []) {
            $existing = isset($config['dependencies']) && is_array($config['dependencies'])
                ? $this->normalizeStringList($config['dependencies'])
                : [];
            $config['dependencies'] = array_values(array_unique(array_merge($existing, $combined)));
            $applied[] = 'dependencies';
        }

        if (isset($params['settings']) && is_array($params['settings'])) {
            $existingSettings = isset($config['settings']) && is_array($config['settings']) ? $config['settings'] : [];
            $config['settings'] = array_replace_recursive($existingSettings, $params['settings']);
            $applied[] = 'settings';
        }

        return $applied;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    /**
     * Check whether a site has any rendering definition attached.
     *
     * Returns a warning string when neither Site Sets nor a sys_template record
     * exists for the root page, otherwise null.
     *
     * @param array<string, mixed> $config
     */
    private function renderingWarningFor(array $config, string $identifier): ?string
    {
        if ($this->hasRenderingDefinition($config, $identifier)) {
            return null;
        }

        $rootPageId = is_numeric($config['rootPageId'] ?? null) ? (int)$config['rootPageId'] : 0;
        if ($rootPageId > 0 && $this->hasTypoScriptTemplate($rootPageId)) {
            return null;
        }

        return 'Site "' . $identifier . '" has no Site Set (dependencies) and no sys_template record on the root page. '
            . 'The frontend will throw "No site configuration or TypoScript template record found". '
            . 'Use action "update" with `dependencies: ["vendor/theme"]` to attach a Site Set.';
    }

    /**
     * @param array<string, mixed> $config
     * @return array{type: string, path: string}|null
     */
    private function ensureRenderingFallback(array $config, string $identifier): ?array
    {
        if ($this->hasRenderingDefinition($config, $identifier) || $this->hasThemeLikeSiteSetAvailable()) {
            return null;
        }

        $siteConfigPath = $this->siteConfiguration->getAllSiteConfigurationPaths()[$identifier] ?? null;
        if (!is_string($siteConfigPath) || $siteConfigPath === '') {
            return null;
        }

        $setupPath = rtrim($siteConfigPath, '/') . '/setup.typoscript';
        if (!is_file($setupPath)) {
            GeneralUtility::writeFile($setupPath, self::GLOBAL_TYPOSCRIPT_INCLUDE, true);
        }

        return [
            'type' => 'siteTypoScript',
            'path' => $setupPath,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hasRenderingDefinition(array $config, string $identifier): bool
    {
        $dependencies = isset($config['dependencies']) && is_array($config['dependencies'])
            ? $this->normalizeStringList($config['dependencies'])
            : [];
        if ($dependencies !== []) {
            return true;
        }

        if ($this->hasSiteTypoScriptInclude($identifier)) {
            return true;
        }

        $rootPageId = is_numeric($config['rootPageId'] ?? null) ? (int)$config['rootPageId'] : 0;
        return $rootPageId > 0 && $this->hasTypoScriptTemplate($rootPageId);
    }

    private function hasSiteTypoScriptInclude(string $identifier): bool
    {
        $siteConfigPath = $this->siteConfiguration->getAllSiteConfigurationPaths()[$identifier] ?? null;
        if (!is_string($siteConfigPath) || $siteConfigPath === '') {
            return false;
        }

        return is_file(rtrim($siteConfigPath, '/') . '/setup.typoscript');
    }

    private function hasThemeLikeSiteSetAvailable(): bool
    {
        foreach ($this->setRegistry->getAllSets() as $set) {
            $haystack = strtolower(implode(' ', [
                $set->name,
                $set->label,
                ...array_filter($set->dependencies, is_string(...)),
                ...array_filter($set->optionalDependencies, is_string(...)),
            ]));
            if (preg_match('/(theme|site[-_ ]?package|sitepackage|template|frontend|preset)/', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasTypoScriptTemplate(int $rootPageId): bool
    {
        $count = $this->connectionPool
            ->getConnectionForTable('sys_template')
            ->count(
                'uid',
                'sys_template',
                [
                    'pid' => $rootPageId,
                    'deleted' => 0,
                    'hidden' => 0,
                ],
            );

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleAddLanguage(string $identifier, array $params): CallToolResult
    {
        $langDef = is_array($params['language'] ?? null) ? $params['language'] : null;
        if ($langDef === null) {
            throw new ValidationException(['language parameter is required for addLanguage action.']);
        }

        $config = $this->loadExistingSiteConfig($identifier);
        $existingLanguages = is_array($config['languages'] ?? null) ? $config['languages'] : [];

        // Determine next languageId
        $maxId = 0;
        foreach ($existingLanguages as $lang) {
            if (is_array($lang) && isset($lang['languageId']) && is_numeric($lang['languageId']) && (int)$lang['languageId'] > $maxId) {
                $maxId = (int)$lang['languageId'];
            }
        }
        $nextId = $maxId + 1;

        /** @var array<string, mixed> $normalizedLangDef */
        $normalizedLangDef = $langDef;
        $newLanguage = $this->buildLanguageConfig($nextId, $normalizedLangDef);
        $this->assertNoLanguageConflict($existingLanguages, $newLanguage);
        $existingLanguages[] = $newLanguage;
        $config['languages'] = $existingLanguages;

        $this->siteWriter->write($identifier, $config);
        $this->languageService->reset();

        return $this->createJsonResult([
            'status' => 'languageAdded',
            'identifier' => $identifier,
            'language' => $newLanguage,
            'totalLanguages' => count($existingLanguages),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleReplaceLanguages(string $identifier, array $params): CallToolResult
    {
        $defaultLangParams = is_array($params['defaultLanguage'] ?? null) ? $params['defaultLanguage'] : null;
        if ($defaultLangParams === null) {
            throw new ValidationException(['defaultLanguage parameter is required for replaceLanguages action.']);
        }
        /** @var array<string, mixed> $defaultLangParams */
        $config = $this->loadExistingSiteConfig($identifier);
        $existingLanguages = is_array($config['languages'] ?? null) ? $config['languages'] : [];
        $config['languages'] = $this->buildLanguageSet(
            $defaultLangParams,
            is_array($params['languages'] ?? null) ? $params['languages'] : [],
            $existingLanguages,
        );

        $this->siteWriter->write($identifier, $config);
        $this->languageService->reset();

        return $this->createJsonResult([
            'status' => 'languagesReplaced',
            'identifier' => $identifier,
            'totalLanguages' => count($config['languages']),
            'config' => $config,
        ]);
    }

    /**
     * @param array<string, mixed> $defaultLanguage
     * @param array<mixed> $additionalLanguages
     * @param array<mixed> $existingLanguages
     * @return list<array<string, mixed>>
     */
    private function buildLanguageSet(array $defaultLanguage, array $additionalLanguages, array $existingLanguages = []): array
    {
        $languages = [];
        $usedIsoCodes = [];
        $usedBases = [];

        $defaultIsoCode = $this->normalizeIsoCode($defaultLanguage);
        $languages[] = $this->buildLanguageConfig(
            0,
            array_merge($defaultLanguage, ['base' => '/']),
            $this->findExistingLanguageByIsoCode($existingLanguages, $defaultIsoCode),
        );
        $usedIsoCodes[] = $defaultIsoCode;
        $usedBases[] = '/';

        $languageId = 1;
        foreach ($additionalLanguages as $langDef) {
            if (!is_array($langDef)) {
                continue;
            }

            /** @var array<string, mixed> $langDef */
            $isoCode = $this->normalizeIsoCode($langDef);
            $normalizedLanguage = $this->buildLanguageConfig(
                $languageId,
                $langDef,
                $this->findExistingLanguageByIsoCode($existingLanguages, $isoCode),
            );

            $this->assertUniqueLanguageInSet($normalizedLanguage, $usedIsoCodes, $usedBases);
            $languages[] = $normalizedLanguage;
            $usedIsoCodes[] = is_string($normalizedLanguage['iso-639-1'] ?? null) ? $normalizedLanguage['iso-639-1'] : $isoCode;
            $usedBases[] = is_string($normalizedLanguage['base'] ?? null) ? $normalizedLanguage['base'] : '';
            $languageId++;
        }

        return $languages;
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
     * Build a single language configuration array.
     *
     * @param int $languageId
     * @param array<string, mixed> $langDef
     * @param array<string, mixed> $existingLanguage
     * @return array<string, mixed>
     */
    private function buildLanguageConfig(int $languageId, array $langDef, array $existingLanguage = []): array
    {
        $title = is_string($langDef['title'] ?? null) ? $langDef['title'] : '';
        $locale = is_string($langDef['locale'] ?? null) ? $langDef['locale'] : '';
        $isoCode = is_string($langDef['iso-639-1'] ?? null) ? strtolower(trim($langDef['iso-639-1'])) : '';
        $explicitFlag = is_string($langDef['flag'] ?? null) ? trim($langDef['flag']) : '';
        $base = is_string($langDef['base'] ?? null)
            ? $langDef['base']
            : (is_string($existingLanguage['base'] ?? null) ? (string)$existingLanguage['base'] : '/');
        $fallbackType = is_string($langDef['fallbackType'] ?? null)
            ? $langDef['fallbackType']
            : (is_string($existingLanguage['fallbackType'] ?? null) ? (string)$existingLanguage['fallbackType'] : 'strict');

        if ($title === '' || $locale === '' || $isoCode === '') {
            throw new ValidationException(['Each language must have title, locale, and iso-639-1 set.']);
        }

        // For non-default languages, default fallbackType to "fallback"
        if ($languageId > 0 && $fallbackType === 'strict') {
            $fallbackType = 'fallback';
        }

        $flag = $this->resolveFlagIdentifier($isoCode, $explicitFlag);

        $languageConfig = $existingLanguage;
        $languageConfig['languageId'] = $languageId;
        $languageConfig['title'] = $title;
        $languageConfig['navigationTitle'] = is_string($langDef['navigationTitle'] ?? null)
            ? $langDef['navigationTitle']
            : (is_string($existingLanguage['navigationTitle'] ?? null) ? (string)$existingLanguage['navigationTitle'] : $title);
        $languageConfig['locale'] = $locale;
        $languageConfig['base'] = $languageId === 0 ? '/' : $base;
        $languageConfig['flag'] = $flag;
        $languageConfig['iso-639-1'] = $isoCode;
        $languageConfig['fallbackType'] = $fallbackType;

        return $languageConfig;
    }

    /**
     * @param array<mixed> $existingLanguages
     * @return array<string, mixed>
     */
    private function findExistingLanguageByIsoCode(array $existingLanguages, string $isoCode): array
    {
        foreach ($existingLanguages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $existingIsoCode = is_string($language['iso-639-1'] ?? null)
                ? strtolower(trim((string)$language['iso-639-1']))
                : '';
            if ($existingIsoCode === $isoCode) {
                /** @var array<string, mixed> $language */
                return $language;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $language
     * @param list<string> $usedIsoCodes
     * @param list<string> $usedBases
     */
    private function assertUniqueLanguageInSet(array $language, array $usedIsoCodes, array $usedBases): void
    {
        $isoCode = is_string($language['iso-639-1'] ?? null) ? (string)$language['iso-639-1'] : '';
        $base = is_string($language['base'] ?? null) ? (string)$language['base'] : '';

        if ($isoCode !== '' && in_array($isoCode, $usedIsoCodes, true)) {
            throw new ValidationException(['Duplicate language iso-639-1 "' . $isoCode . '" in language list.']);
        }

        if ($base !== '' && in_array($base, $usedBases, true)) {
            throw new ValidationException(['Duplicate language base "' . $base . '" in language list.']);
        }
    }

    /**
     * @param array<mixed> $existingLanguages
     * @param array<string, mixed> $newLanguage
     */
    private function assertNoLanguageConflict(array $existingLanguages, array $newLanguage): void
    {
        $newIsoCode = is_string($newLanguage['iso-639-1'] ?? null) ? (string)$newLanguage['iso-639-1'] : '';
        $newBase = is_string($newLanguage['base'] ?? null) ? (string)$newLanguage['base'] : '';

        foreach ($existingLanguages as $language) {
            if (!is_array($language)) {
                continue;
            }

            $existingIsoCode = is_string($language['iso-639-1'] ?? null) ? strtolower(trim((string)$language['iso-639-1'])) : '';
            if ($newIsoCode !== '' && $existingIsoCode === $newIsoCode) {
                throw new ValidationException(['A language with iso-639-1 "' . $newIsoCode . '" already exists for this site.']);
            }

            $existingBase = is_string($language['base'] ?? null) ? (string)$language['base'] : '';
            if ($newBase !== '' && $existingBase === $newBase) {
                throw new ValidationException(['A language with base "' . $newBase . '" already exists for this site.']);
            }
        }
    }

    /**
     * @param array<string, mixed> $language
     */
    private function normalizeIsoCode(array $language): string
    {
        $isoCode = is_string($language['iso-639-1'] ?? null) ? strtolower(trim((string)$language['iso-639-1'])) : '';
        if ($isoCode === '') {
            throw new ValidationException(['Each language must have title, locale, and iso-639-1 set.']);
        }

        return $isoCode;
    }

    private function resolveFlagIdentifier(string $isoCode, string $explicitFlag): string
    {
        if ($explicitFlag !== '') {
            return $explicitFlag;
        }

        return self::FLAG_MAP[$isoCode] ?? $isoCode;
    }

    private function titleFromIdentifier(string $identifier): string
    {
        $title = str_replace('-', ' ', trim($identifier));
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = ucwords($title);

        return $title !== '' ? $title : 'New Website';
    }

    private function normalizePageSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '/';
        }

        $slug = '/' . ltrim($slug, '/');
        $slug = preg_replace('#/+#', '/', $slug) ?? $slug;

        $slug = rtrim($slug, '/');

        return $slug !== '' ? $slug : '/';
    }

    private function hasPageColumn(string $column): bool
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return false;
        }
        $pagesTca = $tca['pages'] ?? [];
        if (!is_array($pagesTca)) {
            return false;
        }
        $columns = $pagesTca['columns'] ?? [];
        if (!is_array($columns)) {
            return false;
        }

        return isset($columns[$column]);
    }

    /**
     * Validate that the site identifier contains only alphanumeric characters and dashes.
     */
    private function validateIdentifier(string $identifier): void
    {
        if ($identifier === '') {
            throw new ValidationException(['identifier is required and must not be empty.']);
        }
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $identifier)) {
            throw new ValidationException(['identifier must contain only alphanumeric characters and dashes.']);
        }
    }

}
