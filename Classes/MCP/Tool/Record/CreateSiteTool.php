<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * Tool for creating and updating TYPO3 site configurations.
 *
 * Supports three actions:
 * - create: Create a new site configuration with root page, base URL, and languages.
 * - addLanguage: Add a language to an existing site configuration.
 * - replaceLanguages: Replace the full language list of an existing site while preserving
 *   unrelated site configuration keys such as route enhancers and settings.
 *
 * Admin-only: only backend admin users may create or modify site configurations.
 * Site configurations are YAML-based and not workspace-versioned; changes take effect immediately.
 */
final class CreateSiteTool extends AbstractRecordTool
{
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
                . 'Action "create" builds a new site config with root page, base URL, and optional languages. '
                . 'Action "addLanguage" adds a language to an existing site. '
                . 'Action "replaceLanguages" replaces the full language list of an existing site while preserving other site settings. '
                . 'Site configurations are YAML-based and take effect immediately (not workspace-versioned). '
                . 'Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create" for a new site, "addLanguage" to append one language to an existing site, or "replaceLanguages" to replace the full language list of an existing site.',
                        'enum' => ['create', 'addLanguage', 'replaceLanguages'],
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'Site identifier (alphanumeric and dashes only, e.g. "main", "my-site").',
                    ],
                    'rootPageId' => [
                        'type' => 'integer',
                        'description' => 'Root page UID for the site (create action only).',
                    ],
                    'base' => [
                        'type' => 'string',
                        'description' => 'Base URL of the site, e.g. "https://example.com/" (create action only).',
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
        $this->ensureAdmin();

        $action = is_string($params['action'] ?? null) ? $params['action'] : '';
        $identifier = is_string($params['identifier'] ?? null) ? $params['identifier'] : '';

        $this->validateIdentifier($identifier);

        return match ($action) {
            'create' => $this->handleCreate($identifier, $params),
            'addLanguage' => $this->handleAddLanguage($identifier, $params),
            'replaceLanguages' => $this->handleReplaceLanguages($identifier, $params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Use "create", "addLanguage", or "replaceLanguages".']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleCreate(string $identifier, array $params): CallToolResult
    {
        $rootPageId = is_numeric($params['rootPageId'] ?? null) ? (int)$params['rootPageId'] : 0;
        $base = is_string($params['base'] ?? null) ? trim($params['base']) : '';

        $errors = [];
        if ($rootPageId <= 0) {
            $errors[] = 'rootPageId is required and must be a positive integer.';
        }
        if ($base === '') {
            $errors[] = 'base is required and must be a non-empty string.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Validate root page exists
        $page = BackendUtility::getRecord('pages', $rootPageId, 'uid,title');
        if ($page === null) {
            throw new ValidationException(['Root page with UID ' . $rootPageId . ' does not exist.']);
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

        $this->siteWriter->write($identifier, $config);

        return $this->createJsonResult([
            'status' => 'created',
            'identifier' => $identifier,
            'config' => $config,
        ]);
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

    /**
     * Ensure the current backend user has admin privileges.
     */
    private function ensureAdmin(): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            throw new ValidationException(['This tool requires admin privileges.']);
        }
    }
}
