<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use InvalidArgumentException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for retrieving the TYPO3 page tree
 */
final class GetPageTreeTool extends AbstractRecordTool
{
    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        protected readonly SiteInformationService $siteInformationService,
        protected readonly LanguageService $languageService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * Get the tool schema
     *
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        $schema = [
            'description' => 'Get the TYPO3 page tree structure as a readable text tree.Essential for understanding page hierarchy before creating new pages, finding pages by their position, and verifying parent-child relationships.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'startPage' => [
                        'type' => 'integer',
                        'description' => 'The page ID to start from (0 for root)',
                    ],
                    'depth' => [
                        'type' => 'integer',
                        'description' => 'The depth of pages to retrieve (default: 3)',
                    ],
                ],
                'required' => ['startPage'],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (\count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to show translated page titles (e.g., "de", "fr"). Shows translation status for each page.',
                'enum' => $availableLanguages,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'idempotentHint' => true,
        ];

        return $schema;
    }

    /**
     * Execute the tool logic
     *
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {

        $startPage = is_numeric($params['startPage'] ?? null) ? (int) $params['startPage'] : 0;
        $depth = is_numeric($params['depth'] ?? null) ? (int) $params['depth'] : 3;
        $languageUid = null;

        // Handle language parameter if provided
        if (isset($params['language']) && \is_string($params['language'])) {
            $languageUid = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageUid === null) {
                throw new InvalidArgumentException('Unknown language code: ' . $params['language']);
            }
        }

        // Get page tree with the specified parameters
        $pageTree = $this->getPageTree($startPage, $depth, $languageUid);

        // Collect all page UIDs from the tree
        $pageUids = $this->collectPageUids($pageTree);

        // Get record counts for all pages
        $recordCounts = $this->getRecordCounts($pageUids);

        // Get plugin hints for all pages
        $pluginHints = [];
        foreach ($pageUids as $uid) {
            $hint = $this->getPluginStorageHints($uid);
            if ($hint) {
                $pluginHints[$uid] = $hint;
            }
        }

        // Convert the page tree to a text-based tree with indentation
        $textTree = $this->renderTextTree($pageTree, 0, $languageUid, $recordCounts, $pluginHints);

        return new CallToolResult([new TextContent($textTree)]);
    }

    /**
     * Get the page tree
     *
     * @return list<array<string, mixed>>
     */
    protected function getPageTree(int $startPage, int $depth, ?int $languageUid = null): array
    {
        // Get database connection for pages table
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        // Only apply the DeletedRestriction to filter out deleted pages
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        // Build the query
        $query = $queryBuilder->select('*')
            ->from('pages');

        // Filter by pid (parent ID) for the starting page
        if ($startPage === 0) {
            // Root level pages have pid=0
            $query->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            );
        } else {
            // Get subpages of the specified page
            $query->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($startPage, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            );
        }

        // Order by sorting
        $query->orderBy('sorting');

        // Execute the query
        $pages = $query->executeQuery()->fetchAllAssociative();

        // Set up context for language and visibility
        $context = GeneralUtility::makeInstance(Context::class);

        // Set up language aspect if needed
        if ($languageUid !== null && $languageUid > 0) {
            $languageAspect = new LanguageAspect(
                $languageUid,
                $languageUid,
                LanguageAspect::OVERLAYS_MIXED,
                [$languageUid],
            );
            $context->setAspect('language', $languageAspect);
        }

        // Create PageRepository with context
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

        // Process the result
        $pageTree = [];
        foreach ($pages as $page) {
            $pageData = [
                'uid' => is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0,
                'pid' => is_numeric($page['pid'] ?? null) ? (int) $page['pid'] : 0,
                'title' => \is_scalar($page['title'] ?? null) ? (string) $page['title'] : '',
                'nav_title' => \is_scalar($page['nav_title'] ?? null) ? (string) $page['nav_title'] : '',
                'hidden' => (bool) ($page['hidden'] ?? false),
                'doktype' => is_numeric($page['doktype'] ?? null) ? (int) $page['doktype'] : 0,
                'subpageCount' => 0,
                'url' => $this->siteInformationService->generatePageUrl(is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0),
            ];

            // Get language overlay if language specified
            if ($languageUid !== null && $languageUid > 0) {
                $overlaidPage = $pageRepository->getPageOverlay($page, $languageUid);

                if ($overlaidPage !== $page) {
                    // Apply overlay data
                    $pageData['title'] = \is_scalar($overlaidPage['title'] ?? null) && (string) $overlaidPage['title'] !== '' ? (string) $overlaidPage['title'] : $pageData['title'];
                    $pageData['nav_title'] = \is_scalar($overlaidPage['nav_title'] ?? null) && (string) $overlaidPage['nav_title'] !== '' ? (string) $overlaidPage['nav_title'] : $pageData['nav_title'];
                    $pageData['hidden'] = (bool) ($overlaidPage['hidden'] ?? false);
                    $pageData['_translated'] = true;
                } else {
                    $pageData['_translated'] = false;
                }
            }

            // Check if there are subpages if depth > 1
            if ($depth > 1) {
                $subpages = $this->getPageTree($pageData['uid'], $depth - 1, $languageUid);
                $pageData['subpages'] = $subpages;
                $pageData['subpageCount'] = \count($subpages);
            } else {
                // We're at max depth, count the number of subpages
                $pageData['subpageCount'] = $this->countSubpages($pageData['uid']);
            }

            $pageTree[] = $pageData;
        }

        return $pageTree;
    }

    /**
     * Count the number of subpages for a page
     */
    protected function countSubpages(int $pageId): int
    {
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $query = $queryBuilder->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            );

        $count = $query->executeQuery()->fetchOne();
        return is_numeric($count) ? (int) $count : 0;
    }


    /**
     * Collect all page UIDs from the tree structure
     *
     * @param list<array<string, mixed>> $pageTree
     * @return list<int>
     */
    protected function collectPageUids(array $pageTree): array
    {
        $uids = [];

        foreach ($pageTree as $page) {
            $pageUid = is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0;
            if ($pageUid > 0) {
                $uids[] = $pageUid;
            }

            if (isset($page['subpages']) && \is_array($page['subpages'])) {
                /** @var list<array<string, mixed>> $subpages */
                $subpages = array_values(array_filter($page['subpages'], is_array(...)));
                $uids = array_merge($uids, $this->collectPageUids($subpages));
            }
        }

        return $uids;
    }

    /**
     * Get record counts for given page UIDs
     *
     * @param list<int> $pageUids
     * @return array<int, array<string, int>>
     */
    protected function getRecordCounts(array $pageUids): array
    {
        if (empty($pageUids)) {
            return [];
        }

        $recordCounts = [];

        // Get accessible tables (exclude read-only system tables)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(false);

        foreach ($accessibleTables as $table => $accessInfo) {
            // Skip pages table itself
            if ($table === 'pages') {
                continue;
            }

            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable($table);

            // Only apply DeletedRestriction
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            // Count records grouped by pid
            $counts = $queryBuilder
                ->select('pid')
                ->addSelectLiteral('COUNT(*) AS count')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter(
                            $pageUids,
                            ArrayParameterType::INTEGER,
                        ),
                    ),
                )
                ->groupBy('pid')
                ->executeQuery()
                ->fetchAllAssociative();

            // Store counts
            foreach ($counts as $row) {
                $pid = is_numeric($row['pid'] ?? null) ? (int) $row['pid'] : 0;
                $count = is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0;

                if (!isset($recordCounts[$pid])) {
                    $recordCounts[$pid] = [];
                }

                $recordCounts[$pid][$table] = $count;
            }
        }

        return $recordCounts;
    }

    /**
     * Get plugin storage hints for a page
     */
    protected function getPluginStorageHints(int $pageId): string
    {
        $hints = '';

        // Query for plugins on this page
        $queryBuilder = $this->connectionPool
            ->getQueryBuilderForTable('tt_content');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $plugins = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                    $queryBuilder->expr()->neq('pi_flexform', $queryBuilder->createNamedParameter('')),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($plugins as $plugin) {
            $pluginIdentifier = $this->getPluginIdentifier($plugin);

            // Check for news plugin with startingpoint configuration
            $pluginFlexform = \is_string($plugin['pi_flexform'] ?? null) ? $plugin['pi_flexform'] : '';
            if ($pluginIdentifier === 'news_pi1' && $pluginFlexform !== '') {
                // Simple regex to extract startingpoint value
                if (preg_match('/<field index="settings\.startingpoint">.*?<value[^>]*>(\d+)<\/value>/s', $pluginFlexform, $matches)) {
                    $storagePid = (int) $matches[1];
                    $hints .= ' [news plugin → pid:' . $storagePid . ']';
                }
            }
        }

        // Debug: Remove debug for now
        // The issue seems to be with the condition or regex

        return $hints;
    }

    /**
     * Determine the logical plugin identifier for a tt_content record.
     *
     * TYPO3 v14 plugin CTypes use the CType directly. Legacy list-based plugins
     * still fall back to list_type when CType is "list".
     *
     * @param array<string, mixed> $plugin
     */
    protected function getPluginIdentifier(array $plugin): string
    {
        $cType = \is_scalar($plugin['CType'] ?? null) ? (string) $plugin['CType'] : '';
        if ($cType !== '' && $cType !== 'list') {
            return $cType;
        }

        if ($cType === 'list' && \is_scalar($plugin['list_type'] ?? null)) {
            return (string) $plugin['list_type'];
        }

        return $cType;
    }

    /**
     * Get human-readable doktype label
     */
    protected function getDoktypeLabel(int $doktype): string
    {
        return match ($doktype) {
            PageRepository::DOKTYPE_DEFAULT => 'Page',
            PageRepository::DOKTYPE_LINK => 'Link',
            PageRepository::DOKTYPE_SHORTCUT => 'Shortcut',
            PageRepository::DOKTYPE_BE_USER_SECTION => 'Backend Section',
            PageRepository::DOKTYPE_MOUNTPOINT => 'Mount Point',
            PageRepository::DOKTYPE_SPACER => 'Spacer',
            PageRepository::DOKTYPE_SYSFOLDER => 'System Folder',
            default => 'Unknown Type',
        };
    }

    /**
     * Render the page tree as a text-based tree with indentation
     *
     * @param list<array<string, mixed>> $pageTree
     * @param array<int, array<string, int>> $recordCounts
     * @param array<int, string> $pluginHints
     */
    protected function renderTextTree(array $pageTree, int $level = 0, ?int $languageUid = null, array $recordCounts = [], array $pluginHints = []): string
    {
        $result = '';
        $indent = str_repeat('  ', $level);

        foreach ($pageTree as $page) {
            $pageTitle = \is_scalar($page['title'] ?? null) ? (string) $page['title'] : '';
            $pageNavTitle = \is_scalar($page['nav_title'] ?? null) ? (string) $page['nav_title'] : '';
            $pageUid = is_numeric($page['uid'] ?? null) ? (int) $page['uid'] : 0;
            $title = $pageNavTitle !== '' ? $pageNavTitle : $pageTitle;
            $hiddenMark = !empty($page['hidden']) ? ' [HIDDEN]' : '';
            $doktypeLabel = $this->getDoktypeLabel(is_numeric($page['doktype'] ?? null) ? (int) $page['doktype'] : 0);

            // Start building the line: [uid] Title [Type]
            $result .= $indent . '- [' . $pageUid . '] ' . $title . ' [' . $doktypeLabel . ']' . $hiddenMark;

            // Add translation status if language specified
            if ($languageUid !== null && $languageUid > 0) {
                if (isset($page['_translated'])) {
                    $result .= $page['_translated'] ? ' [TRANSLATED]' : ' [NOT TRANSLATED]';
                }
            }

            // Add record counts if available
            if ($pageUid > 0 && !empty($recordCounts[$pageUid])) {
                foreach ($recordCounts[$pageUid] as $table => $count) {
                    $result .= ' [' . $table . ': ' . $count . ']';
                }
            }

            // Add URL if available
            if (\is_scalar($page['url'] ?? null) && (string) $page['url'] !== '') {
                $result .= ' - ' . (string) $page['url'];
            }

            // If the page has subpages but we've reached max depth, show the count
            $subpageCount = is_numeric($page['subpageCount'] ?? null) ? (int) $page['subpageCount'] : 0;
            if (empty($page['subpages']) && $subpageCount > 0) {
                $result .= ' (' . $subpageCount . ' subpages)';
            }

            // Add plugin hints if available
            if ($pageUid > 0 && !empty($pluginHints[$pageUid])) {
                $result .= $pluginHints[$pageUid];
            }

            $result .= PHP_EOL;

            if (isset($page['subpages']) && \is_array($page['subpages'])) {
                /** @var list<array<string, mixed>> $subpages */
                $subpages = array_values(array_filter($page['subpages'], is_array(...)));
                if ($subpages !== []) {
                    $result .= $this->renderTextTree($subpages, $level + 1, $languageUid, $recordCounts, $pluginHints);
                }
            }
        }

        return $result;
    }

}
