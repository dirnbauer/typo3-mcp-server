<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Audit page tree for content quality and SEO issues.
 *
 * @phpstan-type AuditIssue array<string, mixed>
 */
final class ContentAuditTool extends AbstractRecordTool
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const DEFAULT_DEPTH = 5;
    private const MAX_DEPTH = 10;

    private const AVAILABLE_CHECKS = [
        'missing_meta_description',
        'missing_alt_text',
        'empty_content',
        'pages_without_content',
        'missing_page_title',
    ];

    private const TEXT_CTYPES = ['text', 'textmedia', 'textpic'];

    public function __construct(
        TableAccessService $tableAccessService,
        WorkspaceContextService $workspaceContextService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct($tableAccessService, $workspaceContextService);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getToolSchema(): array
    {
        return [
            'description' => 'Audit page tree for content quality and SEO issues. '
                . 'Scans pages and content elements for common problems like missing meta descriptions, '
                . 'missing image alt text, empty content elements, or pages without any content. '
                . 'Returns a structured report grouped by check type with actionable issue details.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'rootPageId' => [
                        'type' => 'integer',
                        'description' => 'Root page ID to audit (includes all subpages). Default: site root (page 1).',
                        'default' => 1,
                    ],
                    'checks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => self::AVAILABLE_CHECKS,
                        ],
                        'description' => 'Check types to run. Default: all checks. Options: '
                            . implode(', ', self::AVAILABLE_CHECKS),
                    ],
                    'depth' => [
                        'type' => 'integer',
                        'description' => 'Maximum page tree depth to scan (default: 5, max: 10)',
                        'default' => self::DEFAULT_DEPTH,
                        'minimum' => 1,
                        'maximum' => self::MAX_DEPTH,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum issues per check type (default: 50, max: 200)',
                        'default' => self::DEFAULT_LIMIT,
                        'minimum' => 1,
                        'maximum' => self::MAX_LIMIT,
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    private function getCurrentWorkspaceId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication ? ($backendUser->workspace ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $rootPageId = is_numeric($params['rootPageId'] ?? null) ? (int)$params['rootPageId'] : 1;
        $depth = is_numeric($params['depth'] ?? null) ? min((int)$params['depth'], self::MAX_DEPTH) : self::DEFAULT_DEPTH;
        $limit = is_numeric($params['limit'] ?? null) ? min((int)$params['limit'], self::MAX_LIMIT) : self::DEFAULT_LIMIT;

        if ($depth < 1) {
            $depth = self::DEFAULT_DEPTH;
        }
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        $checks = self::AVAILABLE_CHECKS;
        if (\is_array($params['checks'] ?? null) && $params['checks'] !== []) {
            $requestedChecks = [];
            foreach ($params['checks'] as $check) {
                if (\is_string($check) && \in_array($check, self::AVAILABLE_CHECKS, true)) {
                    $requestedChecks[] = $check;
                }
            }
            if ($requestedChecks !== []) {
                $checks = $requestedChecks;
            }
        }

        // Resolve page subtree
        $pageUids = $this->resolvePageSubtree($rootPageId, $depth);
        if ($pageUids === []) {
            throw new ValidationException(['No accessible pages found under page ID ' . $rootPageId]);
        }

        $issues = [];
        $summary = [];
        $totalIssues = 0;
        $truncated = false;

        foreach ($checks as $check) {
            $checkIssues = match ($check) {
                'missing_meta_description' => $this->checkMissingMetaDescription($pageUids, $limit),
                'missing_alt_text' => $this->checkMissingAltText($pageUids, $limit),
                'empty_content' => $this->checkEmptyContent($pageUids, $limit),
                'pages_without_content' => $this->checkPagesWithoutContent($pageUids, $limit),
                default => $this->checkMissingPageTitle($pageUids, $limit),
            };

            $count = \count($checkIssues);
            $summary[$check] = $count;
            $totalIssues += $count;
            $issues[$check] = $checkIssues;

            if ($count >= $limit) {
                $truncated = true;
            }
        }

        $result = [
            'rootPageId' => $rootPageId,
            'pagesScanned' => \count($pageUids),
            'checksRun' => $checks,
            'summary' => $summary,
            'issues' => $issues,
            'totalIssues' => $totalIssues,
            'truncated' => $truncated,
        ];

        $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        return new CallToolResult([new TextContent($json)]);
    }

    /**
     * Resolve all page UIDs in a subtree up to a given depth.
     *
     * @return list<int>
     */
    private function resolvePageSubtree(int $rootPageId, int $maxDepth): array
    {
        $allPageUids = [$rootPageId];
        $currentLevel = [$rootPageId];
        $workspaceId = $this->getCurrentWorkspaceId();

        for ($depth = 0; $depth < $maxDepth && $currentLevel !== []; $depth++) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
                ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

            $rows = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->in('pid', $qb->createNamedParameter($currentLevel, Connection::PARAM_INT_ARRAY)),
                    // Only standard pages (doktype 1) and shortcut pages are content-relevant
                    $qb->expr()->in('doktype', $qb->createNamedParameter([1, 3, 4], Connection::PARAM_INT_ARRAY)),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $currentLevel = [];
            foreach ($rows as $row) {
                $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
                if ($uid > 0) {
                    $allPageUids[] = $uid;
                    $currentLevel[] = $uid;
                }
            }
        }

        return $allPageUids;
    }

    /**
     * @param list<int> $pageUids
     * @return list<AuditIssue>
     */
    private function checkMissingMetaDescription(array $pageUids, int $limit): array
    {
        $qb = $this->createPagesQueryBuilder();

        $rows = $qb->select('uid', 'title')
            ->from('pages')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->or(
                    $qb->expr()->eq('description', $qb->createNamedParameter('')),
                    $qb->expr()->isNull('description'),
                ),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'pageUid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'pageTitle' => \is_scalar($row['title'] ?? null) ? (string)$row['title'] : '',
                'issue' => 'Page has no meta description',
            ];
        }

        return $issues;
    }

    /**
     * @param list<int> $pageUids
     * @return list<AuditIssue>
     */
    private function checkMissingAltText(array $pageUids, int $limit): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb->select('ref.uid', 'ref.uid_foreign', 'ref.tablenames', 'ref.fieldname', 'ref.pid')
            ->addSelectLiteral(
                $qb->quoteIdentifier('f.name') . ' AS file_name',
                $qb->quoteIdentifier('f.uid') . ' AS file_uid',
                $qb->quoteIdentifier('p.title') . ' AS page_title',
            )
            ->from('sys_file_reference', 'ref')
            ->join(
                'ref',
                'sys_file',
                'f',
                $qb->expr()->eq('f.uid', $qb->quoteIdentifier('ref.uid_local')),
            )
            ->leftJoin(
                'ref',
                'pages',
                'p',
                $qb->expr()->eq('p.uid', $qb->quoteIdentifier('ref.pid')),
            )
            ->where(
                $qb->expr()->in('ref.pid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('ref.tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->or(
                    $qb->expr()->eq('ref.alternative', $qb->createNamedParameter('')),
                    $qb->expr()->isNull('ref.alternative'),
                ),
                // Only images
                $qb->expr()->eq('f.type', $qb->createNamedParameter(2, Connection::PARAM_INT)),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'pageUid' => is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0,
                'pageTitle' => \is_scalar($row['page_title'] ?? null) ? (string)$row['page_title'] : '',
                'contentUid' => is_numeric($row['uid_foreign'] ?? null) ? (int)$row['uid_foreign'] : 0,
                'fileName' => \is_scalar($row['file_name'] ?? null) ? (string)$row['file_name'] : '',
                'fileUid' => is_numeric($row['file_uid'] ?? null) ? (int)$row['file_uid'] : 0,
                'issue' => 'Image file reference missing alt text',
            ];
        }

        return $issues;
    }

    /**
     * @param list<int> $pageUids
     * @return list<AuditIssue>
     */
    private function checkEmptyContent(array $pageUids, int $limit): array
    {
        $workspaceId = $this->getCurrentWorkspaceId();
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        $rows = $qb->select('c.uid', 'c.pid', 'c.header', 'c.CType')
            ->addSelectLiteral(
                $qb->quoteIdentifier('p.title') . ' AS page_title',
            )
            ->from('tt_content', 'c')
            ->leftJoin(
                'c',
                'pages',
                'p',
                $qb->expr()->eq('p.uid', $qb->quoteIdentifier('c.pid')),
            )
            ->where(
                $qb->expr()->in('c.pid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->in('c.CType', $qb->createNamedParameter(self::TEXT_CTYPES, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->or(
                    $qb->expr()->eq('c.bodytext', $qb->createNamedParameter('')),
                    $qb->expr()->isNull('c.bodytext'),
                ),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'pageUid' => is_numeric($row['pid'] ?? null) ? (int)$row['pid'] : 0,
                'pageTitle' => \is_scalar($row['page_title'] ?? null) ? (string)$row['page_title'] : '',
                'contentUid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'CType' => \is_scalar($row['CType'] ?? null) ? (string)$row['CType'] : '',
                'header' => \is_scalar($row['header'] ?? null) ? (string)$row['header'] : '',
                'issue' => 'Text content element has empty bodytext',
            ];
        }

        return $issues;
    }

    /**
     * @param list<int> $pageUids
     * @return list<AuditIssue>
     */
    private function checkPagesWithoutContent(array $pageUids, int $limit): array
    {
        $workspaceId = $this->getCurrentWorkspaceId();
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        // Subquery to count content on each page
        $subQb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $subQb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        $subQb->count('*')
            ->from('tt_content')
            ->where(
                $subQb->expr()->eq('tt_content.pid', $qb->quoteIdentifier('pages.uid')),
            );

        $rows = $qb->select('pages.uid', 'pages.title')
            ->from('pages')
            ->where(
                $qb->expr()->in('pages.uid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('pages.doktype', $qb->createNamedParameter(1, Connection::PARAM_INT)),
                '(' . $subQb->getSQL() . ') = 0',
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'pageUid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'pageTitle' => \is_scalar($row['title'] ?? null) ? (string)$row['title'] : '',
                'issue' => 'Page has no content elements',
            ];
        }

        return $issues;
    }

    /**
     * @param list<int> $pageUids
     * @return list<AuditIssue>
     */
    private function checkMissingPageTitle(array $pageUids, int $limit): array
    {
        $qb = $this->createPagesQueryBuilder();

        $rows = $qb->select('uid', 'title')
            ->from('pages')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->or(
                    $qb->expr()->eq('title', $qb->createNamedParameter('')),
                    $qb->expr()->isNull('title'),
                ),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'pageUid' => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0,
                'pageTitle' => \is_scalar($row['title'] ?? null) ? (string)$row['title'] : '',
                'issue' => 'Page has no title',
            ];
        }

        return $issues;
    }

    private function createPagesQueryBuilder(): QueryBuilder
    {
        $workspaceId = $this->getCurrentWorkspaceId();
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));

        return $qb;
    }
}
