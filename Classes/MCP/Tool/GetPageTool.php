<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Utility\RecordFormattingUtility;
use InvalidArgumentException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for retrieving detailed information about a TYPO3 page
 *
 * @phpstan-type PageRow array<string, mixed>
 * @phpstan-type TranslationInfo array{languageId: int, isoCode: string, title: string}
 * @phpstan-type TableRecordsInfo array{total: int, records: list<PageRow>}
 * @phpstan-type PageRecordsInfo array<string, TableRecordsInfo>
 */
final class GetPageTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        protected readonly SiteInformationService $siteInformationService,
        protected readonly McpLanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    protected function getCurrentWorkspaceId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? $backendUser->workspace : 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTableCtrl(string $table): array
    {
        $globalTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($globalTca)) {
            return [];
        }

        $tableConfig = $globalTca[$table] ?? null;
        if (!is_array($tableConfig)) {
            return [];
        }

        $ctrl = $tableConfig['ctrl'] ?? null;
        return is_array($ctrl) ? $ctrl : [];
    }

    /**
     * @return list<mixed>
     */
    protected function getSelectItems(string $table, string $fieldName): array
    {
        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
        if ($fieldConfig === null) {
            return [];
        }

        $config = isset($fieldConfig['config']) && is_array($fieldConfig['config']) ? $fieldConfig['config'] : [];
        $items = $config['items'] ?? null;
        return is_array($items) ? array_values($items) : [];
    }

    protected function resolveSelectItemLabel(string $table, string $fieldName, string $value): ?string
    {
        foreach ($this->getSelectItems($table, $fieldName) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemValue = $item['value'] ?? $item[1] ?? null;
            if (!is_scalar($itemValue) || (string)$itemValue !== $value) {
                continue;
            }

            $label = $item['label'] ?? $item[0] ?? null;
            if (!is_scalar($label)) {
                return null;
            }

            return TableAccessService::translateLabel((string)$label);
        }

        return null;
    }

    /**
     * Get the tool schema
     */
    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        // Get available domains text dynamically
        $domainsText = $this->siteInformationService->getAvailableDomainsText();

        $schema = [
            'description' => 'Get detailed information about a TYPO3 page including its records. Can fetch by page ID (uid or pageId) or URL. Shows content in the specified language when available.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The page ID to retrieve information for. Provide uid (or pageId alias) or url.',
                    ],
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Alias for uid. Provided for ergonomics — either uid, pageId, or url.',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL of the page to retrieve (alternative to uid). Can be full URL, path, or slug. Provide uid or url. ' . $domainsText,
                    ],
                ],
                'oneOf' => [
                    ['required' => ['uid']],
                    ['required' => ['pageId']],
                    ['required' => ['url']],
                ],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to show page and content in specific language (e.g., "de", "fr"). Shows translated content and metadata when available.',
                'enum' => $availableLanguages,
            ];

            // Add deprecated languageId for backward compatibility
            $schema['inputSchema']['properties']['languageId'] = [
                'type' => 'integer',
                'description' => 'DEPRECATED: Use "language" parameter with ISO code instead. Language ID for URL generation.',
                'deprecated' => true,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ];

        return $schema;
    }

    /**
     * Execute the tool logic
     */
    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Handle language parameter
        $languageId = 0;
        if (isset($params['language']) && is_string($params['language'])) {
            // Convert ISO code to language UID
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new \InvalidArgumentException('Unknown language code: ' . $params['language']);
            }
        } elseif (isset($params['languageId']) && is_numeric($params['languageId'])) {
            // Backward compatibility with numeric languageId
            $languageId = (int)$params['languageId'];
        }

        // Determine page UID from uid, pageId alias, or url parameter
        $uid = 0;
        if (isset($params['uid']) && is_numeric($params['uid'])) {
            $uid = (int)$params['uid'];
        } elseif (isset($params['pageId']) && is_numeric($params['pageId'])) {
            $uid = (int)$params['pageId'];
        } elseif (isset($params['url']) && is_string($params['url'])) {
            try {
                $uid = $this->resolveUrlToPageUid($params['url'], $languageId);
            } catch (\Throwable $e) {
                // Re-throw as InvalidArgumentException to preserve the message in error handling
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid page UID or URL. Please provide a valid page ID or URL.');
        }

        // Get page data (with language overlay if applicable)
        $pageData = $this->getPageData($uid, $languageId);

        // Get page URL using SiteInformationService
        $pageUid = is_numeric($pageData['uid'] ?? null) ? (int)$pageData['uid'] : 0;
        $pageUrl = $this->siteInformationService->generatePageUrl($pageUid, $languageId);

        // Get records on this page (filtered by language if specified)
        $recordsInfo = $this->getPageRecords($uid, $languageId);

        // Get available translations for this page
        $translations = $this->getPageTranslations($uid);

        // Build a text representation of the page information
        $result = $this->formatPageInfo($pageData, $recordsInfo, $pageUrl, $languageId, $translations);

        $result = $this->getWorkspaceHint() . $result;

        return new CallToolResult([new TextContent($result)]);
    }

    /**
     * Get basic page data with language overlay if applicable
     *
     * This method uses direct QueryBuilder instead of PageRepository to properly
     * handle workspace-only pages (pages that exist only in a workspace, not yet live).
     */
    /**
     * @return PageRow
     */
    protected function getPageData(int $uid, int $languageId = 0): array
    {
        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        // Apply proper workspace restrictions
        $currentWorkspace = $this->getCurrentWorkspaceId();
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $queryBuilder->select('*')->from('pages');

        // Handle workspace UID lookup (both live UID and workspace versions)
        if ($currentWorkspace > 0) {
            // In workspace context, check both live UID and workspace versions (t3ver_oid)
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                ),
            );
        } else {
            // In live workspace, just filter by UID
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
            );
        }

        $page = $queryBuilder->executeQuery()->fetchAssociative();

        if (!$page) {
            throw new \RuntimeException('Page not found: ' . $uid);
        }

        // Apply workspace overlay: replaces live page data with workspace-modified version
        if ($currentWorkspace > 0) {
            BackendUtility::workspaceOL('pages', $page);
            if ($page === false) {
                throw new \RuntimeException('Page ' . $uid . ' is marked for deletion in current workspace');
            }
        }

        // Restore live UID for workspace overlay records. workspaceOL() replaces uid with the
        // overlay record's uid — we swap back to the live uid (t3ver_oid) for consistent API output
        // and because getPageOverlay() below expects the live uid to find translations.
        if (isset($page['t3ver_oid']) && $page['t3ver_oid'] > 0) {
            $page['uid'] = (int)$page['t3ver_oid'];
        }

        // Apply language overlay if language is specified
        if ($languageId > 0) {
            // Create a context with the specified language for PageRepository overlay
            $context = GeneralUtility::makeInstance(Context::class);
            $languageAspect = new LanguageAspect(
                $languageId,
                $languageId,
                LanguageAspect::OVERLAYS_MIXED,
                [$languageId],
            );
            $context->setAspect('language', $languageAspect);

            $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);
            if (!is_array($page)) {
                throw new \RuntimeException('Page row invalid after workspace overlay');
            }
            $page = $this->applyPageLanguageOverlay($page, $languageId, $pageRepository);
        }

        // Convert some values to their proper types
        $page['uid'] = is_numeric($page['uid'] ?? null) ? (int)$page['uid'] : 0;
        $page['pid'] = is_numeric($page['pid'] ?? null) ? (int)$page['pid'] : 0;
        $page['hidden'] = (bool)$page['hidden'];
        $page['deleted'] = (bool)($page['deleted'] ?? false);

        return $page;
    }

    /**
     * Get available translations for a page
     */
    /**
     * @return list<TranslationInfo>
     */
    protected function getPageTranslations(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        // Apply proper workspace restrictions
        $currentWorkspace = $this->getCurrentWorkspaceId();
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $translations = $queryBuilder->select('sys_language_uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
            )
            ->orderBy('sys_language_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($translations as $translation) {
            $languageId = is_numeric($translation['sys_language_uid'] ?? null) ? (int)$translation['sys_language_uid'] : 0;
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId);
            if ($isoCode) {
                $result[] = [
                    'languageId' => $languageId,
                    'isoCode' => $isoCode,
                    'title' => is_string($translation['title'] ?? null) ? $translation['title'] : '',
                ];
            }
        }

        return $result;
    }

    /**
     * Apply TYPO3 page language overlay and fall back to an explicit workspace-aware
     * translation lookup for workspace-new translations that PageRepository does not
     * surface on its own.
     *
     * @param PageRow $page
     * @return PageRow
     */
    protected function applyPageLanguageOverlay(array $page, int $languageId, PageRepository $pageRepository): array
    {
        $liveUid = is_numeric($page['uid'] ?? null) ? (int)$page['uid'] : 0;
        $overlaidPage = $this->normalizePageRow($pageRepository->getPageOverlay($page, $languageId));

        if ($overlaidPage !== null && $this->isTranslatedPageOverlay($overlaidPage, $liveUid, $languageId)) {
            return $this->mergeTranslatedPageData($page, $overlaidPage, $languageId);
        }

        $workspaceTranslation = $this->findWorkspaceAwarePageTranslation($liveUid, $languageId);
        if ($workspaceTranslation !== null) {
            return $this->mergeTranslatedPageData($page, $workspaceTranslation, $languageId);
        }

        $page['_translated'] = false;
        $page['_language_uid'] = $languageId;

        return $page;
    }

    /**
     * @param PageRow $translatedPage
     */
    protected function isTranslatedPageOverlay(array $translatedPage, int $liveUid, int $languageId): bool
    {
        return $this->pageRowInt($translatedPage, 'sys_language_uid') === $languageId
            && $this->pageRowInt($translatedPage, 'l10n_parent') === $liveUid;
    }

    /**
     * @param PageRow $sourcePage
     * @param PageRow $translatedPage
     * @return PageRow
     */
    protected function mergeTranslatedPageData(array $sourcePage, array $translatedPage, int $languageId): array
    {
        $mergedPage = array_replace($sourcePage, $translatedPage);
        $mergedPage['uid'] = is_numeric($sourcePage['uid'] ?? null) ? (int)$sourcePage['uid'] : 0;
        $mergedPage['_translated'] = true;
        $mergedPage['_language_uid'] = $languageId;

        if (($sourcePage['title'] ?? null) !== ($translatedPage['title'] ?? null) && isset($sourcePage['title'])) {
            $mergedPage['_original_title'] = $sourcePage['title'];
        }

        return $mergedPage;
    }

    /**
     * @return PageRow|null
     */
    protected function findWorkspaceAwarePageTranslation(int $pageUid, int $languageId): ?array
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        $currentWorkspace = $this->getCurrentWorkspaceId();
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $translation = $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->orderBy('t3ver_wsid', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $translation = $this->normalizePageRow($translation);
        if ($translation === null) {
            return null;
        }

        if ($currentWorkspace > 0) {
            BackendUtility::workspaceOL('pages', $translation);
            $translation = $this->normalizePageRow($translation);
        }

        return $translation;
    }

    /**
     * @return PageRow|null
     */
    protected function normalizePageRow(mixed $pageRow): ?array
    {
        if (!is_array($pageRow)) {
            return null;
        }

        $normalizedPage = [];
        foreach ($pageRow as $key => $value) {
            if (is_string($key)) {
                $normalizedPage[$key] = $value;
            }
        }

        return $normalizedPage;
    }

    /**
     * @param PageRow $pageRow
     */
    protected function pageRowInt(array $pageRow, string $fieldName): int
    {
        $value = $pageRow[$fieldName] ?? null;
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Get records on the page grouped by table
     */
    /**
     * @return PageRecordsInfo
     */
    protected function getPageRecords(int $pageId, int $languageId = 0): array
    {
        // Get all tables that can be on a page
        $tables = $this->getContentTables();

        $recordsInfo = [];

        foreach ($tables as $table) {
            $tableInfo = $this->getTableRecordsInfo($table, $pageId);

            if (!empty($tableInfo['records'])) {
                // Filter records by language for tables that have language support
                if ($this->tableHasLanguageSupport($table)) {
                    $tableInfo = $this->filterRecordsByLanguage($tableInfo, $languageId);
                }
                if (!empty($tableInfo['records'])) {
                    $recordsInfo[$table] = $tableInfo;
                }
            }
        }

        return $recordsInfo;
    }

    /**
     * Get a list of content tables that can be on a page using TableAccessService
     */
    /**
     * @return list<string>
     */
    protected function getContentTables(): array
    {
        // Get all accessible tables from TableAccessService (include read-only tables)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);

        // Filter to only include tables that can be on a page (have pid field)
        $contentTables = [];

        foreach (array_keys($accessibleTables) as $table) {
            // Check if the table has a pid column in its TCA configuration
            // This means it can be associated with a page
            if ($this->getTableCtrl($table) !== []) {
                $contentTables[] = $table;
            }
        }

        return $contentTables;
    }

    /**
     * Get information about records from a specific table on a page
     */
    /**
     * @return TableRecordsInfo
     */
    protected function getTableRecordsInfo(string $table, int $pageId): array
    {
        // Skip if table is not accessible
        if (!$this->tableAccessService->canAccessTable($table)) {
            return [
                'total' => 0,
                'records' => [],
            ];
        }

        $connectionPool = $this->connectionPool;
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply proper workspace restrictions
        $currentWorkspace = $this->getCurrentWorkspaceId();
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        // Always include hidden records (like the TYPO3 backend does)

        // First, get the total count of records
        $countQueryBuilder = clone $queryBuilder;
        $totalCountRaw = $countQueryBuilder->count('*')
            ->from($table)
            ->where(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();
        $totalCount = is_numeric($totalCountRaw) ? (int)$totalCountRaw : 0;

        // Now get the limited records
        $query = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            );

        // Limit to 20 records per table
        $query->setMaxResults(20);

        // Order by uid as default, but use TCA sorting field if available
        $tableCtrl = $this->getTableCtrl($table);
        $sortbyField = is_string($tableCtrl['sortby'] ?? null) ? $tableCtrl['sortby'] : '';
        $defaultSortby = is_string($tableCtrl['default_sortby'] ?? null) ? $tableCtrl['default_sortby'] : '';
        if ($sortbyField !== '') {
            $query->orderBy($sortbyField);
        } elseif ($defaultSortby !== '') {
            // Parse the default_sortby field which might contain ORDER BY statements
            $sortbyFields = GeneralUtility::trimExplode(',', str_replace('ORDER BY', '', $defaultSortby), true);
            foreach ($sortbyFields as $sortbyField) {
                $sortbyFieldAndDirection = GeneralUtility::trimExplode(' ', $sortbyField);
                $query->addOrderBy(
                    $sortbyFieldAndDirection[0],
                    (isset($sortbyFieldAndDirection[1]) && strtolower($sortbyFieldAndDirection[1]) === 'desc') ? 'DESC' : 'ASC',
                );
            }
        } else {
            $query->orderBy('uid');
        }

        $records = $query->executeQuery()->fetchAllAssociative();

        // Apply workspace overlay: replaces live record data with workspace-modified version,
        // or removes records marked for deletion in this workspace.
        $processedRecords = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if ($currentWorkspace > 0) {
                BackendUtility::workspaceOL($table, $record);
                if ($record === false) {
                    // Record is marked for deletion in workspace — exclude it
                    $totalCount = max(0, $totalCount - 1);
                    continue;
                }
            }
            if (!is_array($record)) {
                continue;
            }
            // Workspace transparency: expose live UID for workspace overlay records
            if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
                $record['uid'] = (int)$record['t3ver_oid'];
            }
            $processedRecords[] = $record;
        }
        $records = $processedRecords;

        return [
            'total' => $totalCount,
            'records' => $records,
        ];
    }

    /**
     * Check if a table has a hidden field using TCA
     */
    protected function tableHasHiddenField(string $table): bool
    {
        $ctrl = $this->getTableCtrl($table);
        $enablecolumns = $ctrl['enablecolumns'] ?? null;
        return is_array($enablecolumns) && isset($enablecolumns['disabled']);
    }

    /**
     * Check if table has language support
     */
    protected function tableHasLanguageSupport(string $table): bool
    {
        return $this->tableAccessService->getLanguageFieldName($table) !== null;
    }

    /**
     * Filter records by language
     */
    /**
     * @param TableRecordsInfo $tableInfo
     * @return TableRecordsInfo
     */
    protected function filterRecordsByLanguage(array $tableInfo, int $languageId): array
    {
        $filteredRecords = [];

        foreach ($tableInfo['records'] as $record) {
            $recordLang = is_numeric($record['sys_language_uid'] ?? null) ? (int)$record['sys_language_uid'] : 0;

            if ($languageId === 0) {
                // Default language: only show records with sys_language_uid = 0
                if ($recordLang === 0 || $recordLang === -1) {
                    $filteredRecords[] = $record;
                }
            } else {
                // Specific language: show records in that language or default language
                if ($recordLang === $languageId || $recordLang === 0 || $recordLang === -1) {
                    $filteredRecords[] = $record;
                }
            }
        }

        return [
            'total' => count($filteredRecords),
            'records' => $filteredRecords,
        ];
    }

    /**
     * Format page information as readable text
     */
    /**
     * @param PageRow $pageData
     * @param PageRecordsInfo $recordsInfo
     * @param list<TranslationInfo> $translations
     */
    protected function formatPageInfo(array $pageData, array $recordsInfo, ?string $pageUrl = null, int $languageId = 0, array $translations = []): string
    {
        $result = "PAGE INFORMATION\n";
        $result .= "================\n\n";

        $pageUid = is_numeric($pageData['uid'] ?? null) ? (int)$pageData['uid'] : 0;
        $pageTitle = is_scalar($pageData['title'] ?? null) ? (string)$pageData['title'] : '';
        $pageNavTitle = is_scalar($pageData['nav_title'] ?? null) ? (string)$pageData['nav_title'] : '';
        $pageSubtitle = is_scalar($pageData['subtitle'] ?? null) ? (string)$pageData['subtitle'] : '';
        $pagePid = is_numeric($pageData['pid'] ?? null) ? (int)$pageData['pid'] : 0;
        $pageDoktype = is_scalar($pageData['doktype'] ?? null) ? (string)$pageData['doktype'] : '';
        $pageHidden = (bool)($pageData['hidden'] ?? false);
        $pageCrdate = is_numeric($pageData['crdate'] ?? null) ? (int)$pageData['crdate'] : 0;
        $pageTstamp = is_numeric($pageData['tstamp'] ?? null) ? (int)$pageData['tstamp'] : 0;

        // Basic page info
        $result .= 'UID: ' . $pageUid . "\n";
        $result .= 'Title: ' . $pageTitle . "\n";

        if ($pageUrl !== null) {
            $result .= 'URL: ' . $pageUrl . "\n";
        }

        if ($pageNavTitle !== '') {
            $result .= 'Navigation Title: ' . $pageNavTitle . "\n";
        }

        if ($pageSubtitle !== '') {
            $result .= 'Subtitle: ' . $pageSubtitle . "\n";
        }

        $result .= 'Parent Page (PID): ' . $pagePid . "\n";
        $result .= 'Doktype: ' . $pageDoktype . "\n";
        $result .= 'Hidden: ' . ($pageHidden ? 'Yes' : 'No') . "\n";
        $result .= 'Created: ' . date('Y-m-d H:i:s', $pageCrdate) . "\n";
        $result .= 'Last Modified: ' . date('Y-m-d H:i:s', $pageTstamp) . "\n";

        // Add language/translation information
        if ($languageId > 0) {
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown';
            $result .= 'Language: ' . strtoupper($isoCode) . " (ID: $languageId)\n";
            $result .= 'Translated: ' . (($pageData['_translated'] ?? false) ? 'Yes' : 'No') . "\n";
        }

        // Show available translations
        if (!empty($translations)) {
            $result .= 'Available Translations: ';
            $translationList = [];
            foreach ($translations as $translation) {
                $translationList[] = strtoupper($translation['isoCode']);
            }
            $result .= implode(', ', $translationList) . "\n";
        }

        $result .= "\n";

        // Records on the page
        $result .= "RECORDS ON THIS PAGE\n";
        $result .= "===================\n\n";

        // Handle tt_content specially - group by column position
        if (isset($recordsInfo['tt_content'])) {
            $result .= $this->formatContentElements($recordsInfo['tt_content'], $pageUid);
            // Remove tt_content from the recordsInfo so we don't process it again below
            unset($recordsInfo['tt_content']);
        }

        // Process other tables
        foreach ($recordsInfo as $table => $tableInfo) {
            $tableLabel = TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));
            $totalCount = $tableInfo['total'];
            $records = $tableInfo['records'];
            $displayCount = count($records);
            $result .= 'Table: ' . $tableLabel . ' (' . $table . ') - ' . $totalCount . " total records\n";

            if ($displayCount > 0) {
                foreach ($records as $record) {
                    $title = RecordFormattingUtility::getRecordTitle($table, $record);
                    $recordUid = is_numeric($record['uid'] ?? null) ? (int)$record['uid'] : 0;
                    $result .= '- [' . $recordUid . '] ' . $title . "\n";
                }

                if ($displayCount < $totalCount) {
                    $result .= '  (showing ' . $displayCount . ' of ' . $totalCount . " records)\n";
                }
            } else {
                $result .= "  No records found\n";
            }

            $result .= "\n";
        }

        return $result;
    }

    /**
     * Format content elements grouped by column position
     */
    /**
     * @param TableRecordsInfo $contentInfo
     */
    protected function formatContentElements(array $contentInfo, int $pageId): string
    {
        $result = "Content Elements (tt_content)\n";
        $result .= "----------------------------\n";
        $result .= 'Total: ' . $contentInfo['total'] . " elements\n\n";
        $imageCounts = $this->countVisibleFileReferencesByParentUid(
            'tt_content',
            array_values(array_filter(
                array_map(
                    static fn(array $record): int => is_numeric($record['uid'] ?? null) ? (int)$record['uid'] : 0,
                    $contentInfo['records'],
                ),
                static fn(int $uid): bool => $uid > 0,
            )),
        );

        // Get column position definitions for this specific page
        $hasCustomLayout = false;
        $colPosDefs = RecordFormattingUtility::getColumnPositionDefinitions($pageId, $hasCustomLayout);

        // Determine which columns are actually defined in the backend layout
        $definedColumns = array_keys($colPosDefs);

        // Group content elements by column position
        $groupedElements = [];
        foreach ($contentInfo['records'] as $record) {
            $colPos = is_numeric($record['colPos'] ?? null) ? (int)$record['colPos'] : 0;
            if (!isset($groupedElements[$colPos])) {
                $groupedElements[$colPos] = [];
            }
            $groupedElements[$colPos][] = $record;
        }

        // Sort by column position
        ksort($groupedElements);

        // Output each column with its elements
        foreach ($groupedElements as $colPos => $elements) {
            $colPosName = $colPosDefs[$colPos] ?? 'Column ' . $colPos;
            $result .= 'Column: ' . $colPosName . ' [colPos: ' . $colPos . '] - ' . count($elements) . " elements\n";

            // Check if this column exists in the backend layout (only warn if custom layout is in use)
            if ($hasCustomLayout && !in_array($colPos, $definedColumns)) {
                $result .= "⚠️  Note: This column is not defined in the current backend layout\n";
                $result .= "💡 Tip: Content in this column may not be visible in the frontend\n";
            }

            foreach ($elements as $element) {
                $title = RecordFormattingUtility::getRecordTitle('tt_content', $element);
                $cType = is_scalar($element['CType'] ?? null) ? (string)$element['CType'] : 'unknown';
                $cTypeLabel = RecordFormattingUtility::getContentTypeLabel($cType);
                $elementUid = is_numeric($element['uid'] ?? null) ? (int)$element['uid'] : 0;
                $result .= '- [' . $elementUid . '] ' . $title . ' (Type: ' . $cTypeLabel . ' [' . $cType . "])\n";

                if (in_array($cType, ['text', 'textpic', 'textmedia'], true) && is_string($element['bodytext'] ?? null) && $element['bodytext'] !== '') {
                    $bodytext = strip_tags($element['bodytext']);
                    $bodytext = mb_substr($bodytext, 0, 100) . (mb_strlen($bodytext) > 100 ? '...' : '');
                    $result .= '  Text: ' . $bodytext . "\n";
                }

                $imageCount = $imageCounts[$elementUid] ?? 0;
                if (in_array($cType, ['image', 'textpic', 'textmedia'], true) && $imageCount > 0) {
                    $result .= '  Images: ' . $imageCount . "\n";
                }

                if ($cType === 'html' && is_string($element['bodytext'] ?? null) && $element['bodytext'] !== '') {
                    $result .= "  Contains HTML code\n";
                }

                if (!in_array($cType, ['text', 'textpic', 'textmedia', 'image', 'html'], true)) {
                    $pluginIdentifier = $this->getPluginIdentifier($element);
                    if ($pluginIdentifier !== null) {
                        $pluginName = $this->getPluginLabel($pluginIdentifier);
                        $result .= '  Plugin: ' . $pluginName . ' [' . $pluginIdentifier . "]\n";

                        $pluginTable = $this->getPluginDataTable($pluginIdentifier);
                        if ($pluginTable) {
                            $isWorkspaceCapable = $this->isTableWorkspaceCapable($pluginTable);
                            if (!$isWorkspaceCapable) {
                                $result .= "  ⚠️  Note: This plugin's data table (" . $pluginTable . ") is not workspace-capable\n";
                                $result .= "  💡 Tip: Look for record storage folders (doktype=254) to find and edit the actual records\n";
                            }
                        }

                        if (!empty($element['pi_flexform'])) {
                            $result .= "  Has configuration (FlexForm)\n";
                        }
                    }
                }
            }

            $result .= "\n";
        }

        return $result;
    }

    /**
     * @param list<int> $parentUids
     * @return array<int, int>
     */
    protected function countVisibleFileReferencesByParentUid(string $parentTable, array $parentUids): array
    {
        if ($parentUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $currentWorkspace = $this->getCurrentWorkspaceId();

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $references = $queryBuilder
            ->select('uid', 't3ver_oid', 'uid_foreign')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($parentTable)),
                $queryBuilder->expr()->in('uid_foreign', $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        $seenLogicalReferenceUids = [];
        foreach ($references as $reference) {
            $parentUid = is_numeric($reference['uid_foreign'] ?? null) ? (int)$reference['uid_foreign'] : 0;
            if ($parentUid <= 0) {
                continue;
            }

            $logicalReferenceUid = is_numeric($reference['t3ver_oid'] ?? null) && (int)$reference['t3ver_oid'] > 0
                ? (int)$reference['t3ver_oid']
                : (is_numeric($reference['uid'] ?? null) ? (int)$reference['uid'] : 0);

            if ($logicalReferenceUid <= 0 || isset($seenLogicalReferenceUids[$parentUid][$logicalReferenceUid])) {
                continue;
            }

            $seenLogicalReferenceUids[$parentUid][$logicalReferenceUid] = true;
            $counts[$parentUid] = ($counts[$parentUid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Resolve a URL to a page UID
     *
     * @param string $url The URL to resolve (can be full URL, path, or slug)
     * @param int $languageId The language ID to use for resolution
     * @return int The resolved page UID
     * @throws \Exception If the URL cannot be resolved
     */
    protected function resolveUrlToPageUid(string $url, int $languageId = 0): int
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        // Normalize URLs without a scheme: if the input starts with a known site domain,
        // prepend https:// so parse_url extracts the host correctly.
        if (!str_contains($url, '://') && !str_starts_with($url, '/')) {
            foreach ($siteFinder->getAllSites() as $site) {
                $siteHost = $site->getBase()->getHost();
                if (!empty($siteHost) && str_starts_with($url, $siteHost)) {
                    $url = 'https://' . $url;
                    break;
                }
            }
        }

        // Try to parse as full URL first
        $parsedUrl = parse_url($url);

        // When a scheme+host URL has no path (e.g. "https://example.com"), treat it as home page
        $path = is_array($parsedUrl) && is_string($parsedUrl['path'] ?? null) ? $parsedUrl['path'] : '/';

        // Normalize: ensure leading slash, strip trailing slash (slugs in DB have no trailing slash)
        $path = '/' . trim($path, '/');

        // Special handling for home page
        if ($path === '/') {
            // Try to find the root page from any site
            foreach ($siteFinder->getAllSites() as $site) {
                // Check if this URL belongs to this site (if host is specified)
                if (is_array($parsedUrl) && isset($parsedUrl['host']) && is_string($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    // If site has no host (base is just "/"), skip host check
                    if (!empty($siteHost) && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                }
                return $site->getRootPageId();
            }
        }

        // Try each site to find a match using the router
        $allSites = $siteFinder->getAllSites();
        $matchedAnySite = false;

        foreach ($allSites as $site) {
            try {
                // Check if this URL belongs to this site (if host is specified)
                if (is_array($parsedUrl) && isset($parsedUrl['host']) && is_string($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    // If site has no host (base is just "/"), skip host check
                    if (!empty($siteHost) && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                    $matchedAnySite = true;
                }

                // Try to resolve the path/slug using the site's router
                $router = $site->getRouter();
                $request = $this->createServerRequest($site, $path, $languageId);
                $pageArguments = $router->matchRequest($request);

                if ($pageArguments instanceof PageArguments) {
                    return $pageArguments->getPageId();
                }
            } catch (\Throwable) {
                // Continue to next site
                continue;
            }
        }

        // If host was specified and didn't match any site, don't try generic fallback
        if (is_array($parsedUrl) && isset($parsedUrl['host']) && is_string($parsedUrl['host']) && !$matchedAnySite) {
            throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The domain does not match any configured site.');
        }

        // If no match found via router AND no host was specified, try to find by slug directly in the database
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        // Try exact slug match
        $page = $queryBuilder->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($page) && is_numeric($page['uid'] ?? null)) {
            return (int)$page['uid'];
        }

        throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The path does not match any page.');
    }

    /**
     * Create a server request for URL resolution
     */
    protected function createServerRequest(Site $site, string $path, int $languageId): ServerRequestInterface
    {
        // Ensure path starts with /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Create URI - don't double the slash
        $baseUri = $site->getBase();
        $uri = $baseUri->withPath($path);

        // Create request with proper server variables
        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => $baseUri->getHost() ?: 'localhost',
            'HTTPS' => $baseUri->getScheme() === 'https' ? 'on' : 'off',
            'SERVER_PORT' => $baseUri->getPort() ?: ($baseUri->getScheme() === 'https' ? 443 : 80),
        ];

        $request = new ServerRequest($uri, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('site', $site);

        // Set language attribute
        try {
            $language = $languageId > 0 ? $site->getLanguageById($languageId) : $site->getDefaultLanguage();
            $request = $request->withAttribute('language', $language);
        } catch (\Throwable) {
            // If language not found, use default
            $request = $request->withAttribute('language', $site->getDefaultLanguage());
        }

        // Add normalizedParams which might be needed by the router
        $normalizedParams = GeneralUtility::makeInstance(
            NormalizedParams::class,
            $serverParams,
        );
        $request = $request->withAttribute('normalizedParams', $normalizedParams);

        return $request;
    }

    /**
     * Get a human-readable label for a plugin identifier.
     *
     * @param string $pluginIdentifier
     * @return string
     */
    protected function getPluginLabel(string $pluginIdentifier): string
    {
        $contentTypeLabel = $this->resolveSelectItemLabel('tt_content', 'CType', $pluginIdentifier);
        if ($contentTypeLabel !== null) {
            return $contentTypeLabel;
        }

        // Check TCA for plugin label
        $pluginTypeLabel = $this->resolveSelectItemLabel('tt_content', 'list_type', $pluginIdentifier);
        if ($pluginTypeLabel !== null) {
            return $pluginTypeLabel;
        }

        // Fallback: humanize the identifier
        $parts = explode('_', $pluginIdentifier);
        if (count($parts) > 1) {
            // Remove common prefixes like 'tx_'
            if ($parts[0] === 'tx') {
                array_shift($parts);
            }
            return ucfirst(implode(' ', $parts));
        }

        return $pluginIdentifier;
    }

    /**
     * Try to determine the main data table for a plugin
     *
     * @param string $listType
     * @return string|null
     */
    protected function getPluginDataTable(string $listType): ?string
    {
        // Extract extension key from list_type
        // Common patterns: extensionkey_pi1, tx_extensionkey_list
        $extensionKey = null;

        if (preg_match('/^tx_([a-z0-9]+)_/', $listType, $matches)) {
            $extensionKey = $matches[1];
        } elseif (preg_match('/^([a-z0-9]+)_pi/', $listType, $matches)) {
            $extensionKey = $matches[1];
        }

        if (!$extensionKey) {
            return null;
        }

        // Common table naming patterns
        $possibleTables = [
            'tx_' . $extensionKey . '_domain_model_' . rtrim($extensionKey, 's'), // news -> tx_news_domain_model_news
            'tx_' . $extensionKey . '_' . rtrim($extensionKey, 's'), // simpler pattern
            'tx_' . $extensionKey, // fallback
        ];

        // Check which tables actually exist
        foreach ($possibleTables as $table) {
            if ($this->getTableCtrl($table) !== []) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Determine the logical plugin identifier for a tt_content record.
     */
    /**
     * @param PageRow $element
     */
    protected function getPluginIdentifier(array $element): ?string
    {
        $cType = is_scalar($element['CType'] ?? null) ? (string)$element['CType'] : '';
        if ($cType === '') {
            return null;
        }

        if ($cType === 'list' && !empty($element['list_type'])) {
            return is_scalar($element['list_type']) ? (string)$element['list_type'] : null;
        }

        if (!empty($element['pi_flexform'])) {
            return $cType;
        }

        return null;
    }

    /**
     * Check if a table is workspace capable
     *
     * @param string $table
     * @return bool
     */
    protected function isTableWorkspaceCapable(string $table): bool
    {
        return $this->tableAccessService->getTableAccessInfo($table, false)['workspace_capable'];
    }
}
