<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service for handling language mappings between ISO codes and UIDs
 */
final class LanguageService
{
    /**
     * Cached mapping of ISO codes to language UIDs
     * @var array<string, int>
     */
    private array $isoToUidMap = [];

    /**
     * Cached mapping of language UIDs to ISO codes
     * @var array<int, string>
     */
    private array $uidToIsoMap = [];

    /**
     * Default language ISO code
     */
    private ?string $defaultIsoCode = null;

    /**
     * Whether the mappings have been initialized
     */
    private bool $initialized = false;

    /**
     * ISO codes that exist in at least one site (union — used for tool enums).
     *
     * @var array<string, true>
     */
    private array $isoCodeUnion = [];

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Drop all cached ISO⇄UID mappings.
     *
     * Call this after site configuration changes (CreateSite, addLanguage, replaceLanguages) so
     * subsequent schema calls or translation lookups see the new language layout instead of
     * the first-seen configuration from an earlier tool call in the same MCP session.
     */
    public function reset(): void
    {
        $this->initialized = false;
        $this->isoToUidMap = [];
        $this->uidToIsoMap = [];
        $this->isoCodeUnion = [];
        $this->defaultIsoCode = null;
    }

    /**
     * Initialize language mappings from all sites
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $sites = $this->siteFinder->getAllSites();

        foreach ($sites as $site) {
            $languages = $site->getAllLanguages();

            foreach ($languages as $language) {
                $uid = $language->getLanguageId();
                $isoCode = $this->extractIsoCode($language);

                if ($isoCode !== null) {
                    $this->isoCodeUnion[$isoCode] = true;
                    // Store the mapping (first occurrence wins if there are conflicts)
                    if (!isset($this->isoToUidMap[$isoCode])) {
                        $this->isoToUidMap[$isoCode] = $uid;
                    }
                    if (!isset($this->uidToIsoMap[$uid])) {
                        $this->uidToIsoMap[$uid] = $isoCode;
                    }

                    // Set default language ISO code
                    if ($uid === 0 && $this->defaultIsoCode === null) {
                        $this->defaultIsoCode = $isoCode;
                    }
                }
            }
        }

        $this->initialized = true;
    }

    /**
     * Extract ISO code from SiteLanguage
     * Tries multiple sources in order of preference
     */
    private function extractIsoCode(SiteLanguage $language): ?string
    {
        // Get the language configuration array
        $languageConfig = $language->toArray();

        // 1. Try iso-639-1 configuration (two-letter code like 'en', 'de')
        if (isset($languageConfig['iso-639-1']) && strlen($languageConfig['iso-639-1']) === 2) {
            return strtolower($languageConfig['iso-639-1']);
        }

        // 2. Try to get language code from Locale object
        try {
            $locale = $language->getLocale();
            $languageCode = $locale->getLanguageCode();
            if ($languageCode !== '' && strlen($languageCode) === 2) {
                return strtolower($languageCode);
            }
        } catch (\Throwable) {
            // Locale might not be properly configured
        }

        // 3. Try hreflang (might be like 'en-us', we take the first part)
        $hreflang = $language->getHreflang();
        if (!empty($hreflang)) {
            $parts = explode('-', $hreflang);
            if (!empty($parts[0]) && strlen($parts[0]) === 2) {
                return strtolower($parts[0]);
            }
        }

        // 4. Try to parse locale string as fallback
        if (isset($languageConfig['locale']) && !empty($languageConfig['locale'])) {
            $parts = preg_split('/[_\-\.]/', (string)$languageConfig['locale']);
            if (!empty($parts[0]) && strlen($parts[0]) === 2) {
                return strtolower($parts[0]);
            }
        }

        return null;
    }

    /**
     * Get language UID from ISO code
     *
     * @param string $isoCode Two-letter ISO code (e.g., 'en', 'de')
     * @return int|null Language UID or null if not found
     */
    public function getUidFromIsoCode(string $isoCode): ?int
    {
        $this->initialize();

        $isoCode = strtolower($isoCode);
        return $this->isoToUidMap[$isoCode] ?? null;
    }

    /**
     * Get ISO code from language UID
     *
     * @param int $uid Language UID
     * @return string|null ISO code or null if not found
     */
    public function getIsoCodeFromUid(int $uid): ?string
    {
        $this->initialize();

        return $this->uidToIsoMap[$uid] ?? null;
    }

    /**
     * Get all available language ISO codes
     *
     * @return list<string> Array of ISO codes
     */
    public function getAvailableIsoCodes(): array
    {
        $this->initialize();

        $codes = array_keys($this->isoCodeUnion);
        sort($codes);

        return $codes;
    }

    /**
     * Resolve a language ID for an ISO code using only the site that owns $pageId.
     * Use this for translate/create/update when the global ISO→ID map would pick the wrong site.
     */
    public function getUidFromIsoCodeForPage(int $pageId, string $isoCode): ?int
    {
        $isoCode = strtolower($isoCode);

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return $this->getUidFromIsoCode($isoCode);
        }

        foreach ($site->getAllLanguages() as $language) {
            $extracted = $this->extractIsoCode($language);
            if ($extracted === $isoCode) {
                return $language->getLanguageId();
            }
        }

        return null;
    }

    /**
     * Resolve the ISO code for a language UID using only the site that owns $pageId.
     *
     * The global uidToIsoMap is first-wins across all sites, which produces wrong
     * answers in multi-site installations where different sites assign different
     * ISO codes to the same language UID (e.g. site A has hu=2 while site B has
     * zh=2 — looking up UID 2 without site context returns whichever was indexed
     * first). Callers operating on a specific page should prefer this method.
     */
    public function getIsoCodeFromUidForPage(int $pageId, int $uid): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return $this->getIsoCodeFromUid($uid);
        }

        foreach ($site->getAllLanguages() as $language) {
            if ($language->getLanguageId() === $uid) {
                return $this->extractIsoCode($language);
            }
        }

        return $this->getIsoCodeFromUid($uid);
    }

    /**
     * ISO codes configured on the site that owns $pageId (ordered, deduplicated).
     *
     * Returns an empty list if the page has no site or if the site has no languages
     * with resolvable ISO codes. Use this to build dynamic enums that match the
     * caller's site instead of the union of all sites.
     *
     * @return list<string>
     */
    public function getAvailableIsoCodesForPage(int $pageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return [];
        }

        $codes = [];
        foreach ($site->getAllLanguages() as $language) {
            $isoCode = $this->extractIsoCode($language);
            if ($isoCode !== null && !in_array($isoCode, $codes, true)) {
                $codes[] = $isoCode;
            }
        }

        return $codes;
    }

    /**
     * Get default language ISO code
     *
     * @return string|null Default language ISO code
     */
    public function getDefaultIsoCode(): ?string
    {
        $this->initialize();

        return $this->defaultIsoCode;
    }

    /**
     * Get all language mappings
     *
     * @return array<string, int> Array with ISO codes as keys and UIDs as values
     */
    public function getAllMappings(): array
    {
        $this->initialize();

        return $this->isoToUidMap;
    }

    /**
     * Check if a language ISO code is available
     *
     * @param string $isoCode
     * @return bool
     */
    public function isIsoCodeAvailable(string $isoCode): bool
    {
        $this->initialize();

        return isset($this->isoToUidMap[strtolower($isoCode)]);
    }

    /**
     * Get language information for a specific page
     * This considers the site configuration for the given page
     *
     * @param int $pageId
     * @return list<array{uid: int, isoCode: string, title: string, locale: string, enabled: bool}> Array of language information
     */
    public function getLanguagesForPage(int $pageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $languages = [];

            foreach ($site->getAllLanguages() as $language) {
                $isoCode = $this->extractIsoCode($language);
                if ($isoCode !== null) {
                    $languages[] = [
                        'uid' => $language->getLanguageId(),
                        'isoCode' => $isoCode,
                        'title' => $language->getTitle(),
                        'locale' => (string)$language->getLocale(),
                        'enabled' => $language->isEnabled(),
                    ];
                }
            }

            return $languages;
        } catch (SiteNotFoundException) {
            // Return all available languages if site not found
            return $this->getAllLanguageInfo();
        }
    }

    /**
     * Get information about all available languages
     *
     * @return list<array{uid: int, isoCode: string, title: string, locale: string, enabled: bool}>
     */
    public function getAllLanguageInfo(): array
    {
        $this->initialize();

        $languages = [];
        $seen = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $uid = $language->getLanguageId();

                // Skip if we've already processed this UID
                if (isset($seen[$uid])) {
                    continue;
                }

                $isoCode = $this->extractIsoCode($language);
                if ($isoCode !== null) {
                    $languages[] = [
                        'uid' => $uid,
                        'isoCode' => $isoCode,
                        'title' => $language->getTitle(),
                        'locale' => (string)$language->getLocale(),
                        'enabled' => $language->isEnabled(),
                    ];
                    $seen[$uid] = true;
                }
            }
        }

        // Sort by UID to have consistent ordering
        usort($languages, fn(array $a, array $b): int => $a['uid'] <=> $b['uid']);

        return $languages;
    }
}
