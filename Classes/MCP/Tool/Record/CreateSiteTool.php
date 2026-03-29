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
 * Supports two actions:
 * - create: Create a new site configuration with root page, base URL, and languages.
 * - addLanguage: Add a language to an existing site configuration.
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
        'de' => 'flags-de',
        'fr' => 'flags-fr',
        'es' => 'flags-es',
        'it' => 'flags-it',
        'nl' => 'flags-nl',
        'pl' => 'flags-pl',
        'pt' => 'flags-pt',
        'da' => 'flags-dk',
        'sv' => 'flags-se',
        'no' => 'flags-no',
        'fi' => 'flags-fi',
        'cs' => 'flags-cz',
        'sk' => 'flags-sk',
        'hu' => 'flags-hu',
        'ro' => 'flags-ro',
        'bg' => 'flags-bg',
        'hr' => 'flags-hr',
        'sl' => 'flags-si',
        'el' => 'flags-gr',
        'tr' => 'flags-tr',
        'ru' => 'flags-ru',
        'uk' => 'flags-ua',
        'ja' => 'flags-jp',
        'zh' => 'flags-cn',
        'ko' => 'flags-kr',
        'ar' => 'flags-sa',
        'en' => 'flags-gb',
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
                . 'Site configurations are YAML-based and take effect immediately (not workspace-versioned). '
                . 'Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create" for a new site, "addLanguage" to add a language to an existing site.',
                        'enum' => ['create', 'addLanguage'],
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
                        'description' => 'Default language configuration. Defaults to English (en_US.UTF-8) if omitted (create action only).',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'Language title, e.g. "English".'],
                            'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "en_US.UTF-8".'],
                            'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "en".'],
                        ],
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'Additional languages to add (create action only).',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'description' => 'Language title, e.g. "German".'],
                                'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "de_DE.UTF-8".'],
                                'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "de".'],
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
                            'locale' => ['type' => 'string', 'description' => 'Locale string, e.g. "fr_FR.UTF-8".'],
                            'iso-639-1' => ['type' => 'string', 'description' => 'ISO 639-1 code, e.g. "fr".'],
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

        $action = \is_string($params['action'] ?? null) ? $params['action'] : '';
        $identifier = \is_string($params['identifier'] ?? null) ? $params['identifier'] : '';

        $this->validateIdentifier($identifier);

        return match ($action) {
            'create' => $this->handleCreate($identifier, $params),
            'addLanguage' => $this->handleAddLanguage($identifier, $params),
            default => throw new ValidationException(['Unknown action "' . $action . '". Use "create" or "addLanguage".']),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleCreate(string $identifier, array $params): CallToolResult
    {
        $rootPageId = is_numeric($params['rootPageId'] ?? null) ? (int)$params['rootPageId'] : 0;
        $base = \is_string($params['base'] ?? null) ? trim($params['base']) : '';

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

        // Build default language
        $defaultLangParams = \is_array($params['defaultLanguage'] ?? null) ? $params['defaultLanguage'] : [];
        $defaultLanguage = $this->buildLanguageConfig(0, [
            'title' => \is_string($defaultLangParams['title'] ?? null) ? $defaultLangParams['title'] : 'English',
            'locale' => \is_string($defaultLangParams['locale'] ?? null) ? $defaultLangParams['locale'] : 'en_US.UTF-8',
            'iso-639-1' => \is_string($defaultLangParams['iso-639-1'] ?? null) ? $defaultLangParams['iso-639-1'] : 'en',
            'base' => '/',
            'fallbackType' => 'strict',
        ]);

        $languages = [$defaultLanguage];

        // Build additional languages
        $additionalLanguages = \is_array($params['languages'] ?? null) ? $params['languages'] : [];
        $languageId = 1;
        foreach ($additionalLanguages as $langDef) {
            if (!\is_array($langDef)) {
                continue;
            }
            /** @var array<string, mixed> $langDef */
            $languages[] = $this->buildLanguageConfig($languageId, $langDef);
            $languageId++;
        }

        $config = [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'languages' => $languages,
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
        $langDef = \is_array($params['language'] ?? null) ? $params['language'] : null;
        if ($langDef === null) {
            throw new ValidationException(['language parameter is required for addLanguage action.']);
        }

        // Load existing site config
        $allSites = $this->siteConfiguration->resolveAllExistingSitesRaw();
        if (!isset($allSites[$identifier])) {
            throw new ValidationException(['Site "' . $identifier . '" does not exist.']);
        }

        /** @var array<string, mixed> $config */
        $config = \is_array($allSites[$identifier]) ? $allSites[$identifier] : [];
        $existingLanguages = \is_array($config['languages'] ?? null) ? $config['languages'] : [];

        // Determine next languageId
        $maxId = 0;
        foreach ($existingLanguages as $lang) {
            if (\is_array($lang) && isset($lang['languageId']) && is_numeric($lang['languageId']) && (int)$lang['languageId'] > $maxId) {
                $maxId = (int)$lang['languageId'];
            }
        }
        $nextId = $maxId + 1;

        /** @var array<string, mixed> $normalizedLangDef */
        $normalizedLangDef = $langDef;
        $newLanguage = $this->buildLanguageConfig($nextId, $normalizedLangDef);
        $existingLanguages[] = $newLanguage;
        $config['languages'] = $existingLanguages;

        $this->siteWriter->write($identifier, $config);

        return $this->createJsonResult([
            'status' => 'languageAdded',
            'identifier' => $identifier,
            'language' => $newLanguage,
            'totalLanguages' => \count($existingLanguages),
        ]);
    }

    /**
     * Build a single language configuration array.
     *
     * @param int $languageId
     * @param array<string, mixed> $langDef
     * @return array<string, mixed>
     */
    private function buildLanguageConfig(int $languageId, array $langDef): array
    {
        $title = \is_string($langDef['title'] ?? null) ? $langDef['title'] : '';
        $locale = \is_string($langDef['locale'] ?? null) ? $langDef['locale'] : '';
        $isoCode = \is_string($langDef['iso-639-1'] ?? null) ? $langDef['iso-639-1'] : '';
        $base = \is_string($langDef['base'] ?? null) ? $langDef['base'] : '/';
        $fallbackType = \is_string($langDef['fallbackType'] ?? null) ? $langDef['fallbackType'] : 'strict';

        if ($title === '' || $locale === '' || $isoCode === '') {
            throw new ValidationException(['Each language must have title, locale, and iso-639-1 set.']);
        }

        // For non-default languages, default fallbackType to "fallback"
        if ($languageId > 0 && $fallbackType === 'strict') {
            $fallbackType = 'fallback';
        }

        $flag = self::FLAG_MAP[$isoCode] ?? 'flags-' . $isoCode;

        return [
            'languageId' => $languageId,
            'title' => $title,
            'navigationTitle' => $title,
            'locale' => $locale,
            'base' => $base,
            'flag' => $flag,
            'iso-639-1' => $isoCode,
            'fallbackType' => $fallbackType,
        ];
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
