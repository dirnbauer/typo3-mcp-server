<?php

declare(strict_types=1);

namespace Hn\McpServer\Service\X402;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Applies TYPO3's access, workspace, language, and visibility model to x402 reads.
 */
final readonly class X402ContentAccessService
{
    private const PAYWALL_FIELDS = [
        'tx_x402_paywall_enabled',
        'tx_x402_paywall_price',
        'tx_x402_paywall_description',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableAccessService $tableAccessService,
        private Context $context,
        private LanguageService $languageService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getPage(int $pageUid, ?string $language = null): ?array
    {
        $repository = $this->createPageRepository($language);
        $page = $this->normalizeRow($repository->getPage($pageUid));
        if ($page === []) {
            return null;
        }

        $this->assertPageAccess($page);
        $this->assertPaywallFieldAccess($pageUid);

        return $page;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGatedPages(?int $parentPageUid = null, ?string $language = null): array
    {
        if ($parentPageUid !== null) {
            $parent = $this->getPage($parentPageUid, $language);
            if ($parent === null) {
                throw new ValidationException([sprintf('Page %d is not accessible.', $parentPageUid)]);
            }
        }

        $workspaceId = $this->positiveInt($this->context->getPropertyFromAspect('workspace', 'id', 0));
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId, true));

        $candidateRows = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_x402_paywall_enabled',
                    $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $candidateUids = [];
        foreach ($candidateRows as $row) {
            $uid = $this->positiveInt($row['uid'] ?? null);
            $liveUid = $this->positiveInt($row['t3ver_oid'] ?? null);
            $languageParent = $this->positiveInt($row['l10n_parent'] ?? null);
            if ($languageParent > 0) {
                $uid = $languageParent;
            } elseif ($liveUid > 0) {
                $uid = $liveUid;
            }
            if ($uid > 0) {
                $candidateUids[$uid] = true;
            }
        }

        $repository = $this->createPageRepository($language);
        $pages = [];
        foreach (array_keys($candidateUids) as $candidateUid) {
            $page = $this->normalizeRow($repository->getPage($candidateUid));
            if ($page === [] || !(bool)($page['tx_x402_paywall_enabled'] ?? false)) {
                continue;
            }
            if ($parentPageUid !== null && $this->intValue($page['pid'] ?? null) !== $parentPageUid) {
                continue;
            }
            if (!$this->canAccessPage($page) || !$this->canAccessPaywallFields($candidateUid)) {
                continue;
            }
            $pages[] = $page;
        }

        usort($pages, function (array $left, array $right): int {
            $sorting = $this->intValue($left['sorting'] ?? null) <=> $this->intValue($right['sorting'] ?? null);
            return $sorting !== 0
                ? $sorting
                : $this->intValue($left['uid'] ?? null) <=> $this->intValue($right['uid'] ?? null);
        });

        return $pages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getContentElements(int $pageUid, ?string $language = null): array
    {
        $pageContext = $this->createPageContext($language);
        $repository = GeneralUtility::makeInstance(PageRepository::class, $pageContext);
        $workspaceId = $this->positiveInt($pageContext->getPropertyFromAspect('workspace', 'id', 0));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        if ($workspaceId === 0) {
            $queryBuilder->setRestrictions(new FrontendRestrictionContainer($pageContext));
        } else {
            // In a workspace, live hidden records must remain candidates: versionOL()
            // decides visibility after applying the draft overlay.
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(new DeletedRestriction())
                ->add(new WorkspaceRestriction($workspaceId));
        }

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([0, -1], ArrayParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $content = [];
        foreach ($rows as $row) {
            $row = $this->normalizeRow($row);
            $repository->versionOL('tt_content', $row);
            if (!is_array($row) || $this->intValue($row['pid'] ?? null) !== $pageUid) {
                continue;
            }
            $overlaid = $repository->getLanguageOverlay('tt_content', $row);
            if (!is_array($overlaid)) {
                continue;
            }
            $overlaid = $this->normalizeRow($overlaid);
            if ($this->intValue($overlaid['pid'] ?? null) !== $pageUid) {
                continue;
            }
            $uid = $this->positiveInt($overlaid['uid'] ?? null);
            if ($uid <= 0) {
                continue;
            }
            $content[$uid] = $this->projectContentElement($overlaid, $pageUid);
        }

        $content = array_values($content);
        usort($content, function (array $left, array $right): int {
            $sorting = $this->intValue($left['_sorting'] ?? null) <=> $this->intValue($right['_sorting'] ?? null);
            return $sorting !== 0
                ? $sorting
                : $this->intValue($left['uid'] ?? null) <=> $this->intValue($right['uid'] ?? null);
        });
        foreach ($content as &$element) {
            unset($element['_sorting']);
        }
        unset($element);

        return $content;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public function projectPage(array $page): array
    {
        $pageUid = $this->positiveInt($page['uid'] ?? null);
        $projected = ['uid' => $pageUid, 'pid' => $this->intValue($page['pid'] ?? null)];
        foreach (['title', 'subtitle', 'description', 'abstract'] as $field) {
            if ($this->tableAccessService->canAccessField('pages', $field, '', $pageUid)) {
                $projected[$field] = is_scalar($page[$field] ?? null) ? (string)$page[$field] : '';
            }
        }

        return $projected;
    }

    private function createPageRepository(?string $language): PageRepository
    {
        return GeneralUtility::makeInstance(PageRepository::class, $this->createPageContext($language));
    }

    private function createPageContext(?string $language): Context
    {
        $pageContext = clone $this->context;
        if ($language === null || $language === '') {
            return $pageContext;
        }

        $languageUid = $this->languageService->getUidFromIsoCode($language);
        if ($languageUid === null) {
            throw new ValidationException([sprintf('Unknown language code: %s', $language)]);
        }
        $pageContext->setAspect('language', new LanguageAspect(
            $languageUid,
            $languageUid,
            LanguageAspect::OVERLAYS_MIXED,
            [$languageUid],
        ));

        return $pageContext;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function assertPageAccess(array $page): void
    {
        if ($this->canAccessPage($page)) {
            return;
        }

        throw new ValidationException([sprintf(
            'Permission denied: You do not have access to page %d or it is outside your database mounts.',
            $this->intValue($page['uid'] ?? null),
        )]);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function canAccessPage(array $page): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }

        return $backendUser->isInWebMount($page) !== null
            && $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    private function assertPaywallFieldAccess(int $pageUid): void
    {
        if ($this->canAccessPaywallFields($pageUid)) {
            return;
        }

        throw new ValidationException([
            'Permission denied: the current backend user cannot read the x402 paywall fields.',
        ]);
    }

    private function canAccessPaywallFields(int $pageUid): bool
    {
        foreach (self::PAYWALL_FIELDS as $field) {
            if (!$this->tableAccessService->canAccessField('pages', $field, '', $pageUid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectContentElement(array $row, int $pageUid): array
    {
        $type = is_scalar($row['CType'] ?? null) ? (string)$row['CType'] : '';
        $projected = [
            'uid' => $this->positiveInt($row['uid'] ?? null),
            '_sorting' => $this->intValue($row['sorting'] ?? null),
        ];
        $fieldMap = [
            'CType' => 'type',
            'header' => 'header',
            'bodytext' => 'bodytext',
            'colPos' => 'colPos',
        ];
        foreach ($fieldMap as $field => $outputName) {
            if (!$this->tableAccessService->canAccessField('tt_content', $field, $type, $pageUid)) {
                continue;
            }
            $value = $row[$field] ?? null;
            $projected[$outputName] = is_scalar($value) ? $value : '';
        }

        return $projected;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid = $backendUser instanceof BackendUserAuthentication ? $backendUser->user['uid'] ?? 0 : 0;
        if (!$backendUser instanceof BackendUserAuthentication || !is_numeric($uid) || (int)$uid <= 0) {
            throw new ValidationException(['A real active TYPO3 backend user is required.']);
        }

        return $backendUser;
    }

    private function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
