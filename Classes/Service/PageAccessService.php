<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\AccessDeniedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Central backend page-tree authorization guard for MCP read surfaces.
 *
 * Table permissions and workspace access are separate concerns. Every record
 * tied to a page must additionally be below one of the authenticated backend
 * user's web mounts. TYPO3's isInWebMount() remains the source of truth so
 * page permissions, translations, and workspace overlays follow Core rules.
 */
final readonly class PageAccessService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int|array<string, mixed> $page
     */
    public function assertPageAccess(int|array $page, string $operation = 'read'): void
    {
        if ($this->canAccessPage($page)) {
            return;
        }

        $pageUid = is_array($page) ? $this->toInt($page['uid'] ?? null) : $page;
        throw new AccessDeniedException('page ' . $pageUid . ' outside the backend web mount', $operation);
    }

    /**
     * @param int|array<string, mixed> $page
     */
    public function canAccessPage(int|array $page): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }

        $pageRow = $this->resolvePermissionPageRow($page);
        if ($pageRow === null) {
            return false;
        }

        try {
            if (!$backendUser->doesUserHaveAccess($pageRow, Permission::PAGE_SHOW)) {
                return false;
            }
            if ($backendUser->isInWebMount($pageRow) !== null) {
                return true;
            }

            // A new workspace page has no live UID for a Core rootline yet.
            // Its parent must still be mounted and readable; the page's own
            // PAGE_SHOW permission was checked directly above.
            $workspaceId = $this->toInt($pageRow['t3ver_wsid'] ?? null);
            $liveUid = $this->toInt($pageRow['t3ver_oid'] ?? null);
            $parentUid = $this->toInt($pageRow['pid'] ?? null);
            return $workspaceId > 0
                && $liveUid === 0
                && $parentUid > 0
                && $this->canAccessPage($parentUid);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A workspace version can have pid=-1. Resolve its live record before
     * checking the containing page so review/list operations cannot bypass
     * web mounts by selecting version rows directly.
     *
     * @param array<string, mixed> $record
     */
    public function canAccessRecord(string $table, array $record): bool
    {
        if ($table === 'pages') {
            return $this->canAccessPage($record);
        }

        $pageUid = $this->resolveRecordPageUid($table, $record);
        return $pageUid > 0 && $this->canAccessPage($pageUid);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function assertRecordAccess(string $table, array $record, string $operation = 'read'): void
    {
        if ($this->canAccessRecord($table, $record)) {
            return;
        }

        $uid = $this->toInt($record['uid'] ?? null);
        throw new AccessDeniedException($table . ' record ' . $uid . ' outside the backend web mount', $operation);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function resolveRecordPageUid(string $table, array $record): int
    {
        if ($table === 'pages') {
            return $this->toInt($record['uid'] ?? null);
        }

        $pageUid = $this->toInt($record['pid'] ?? null);
        if ($pageUid <= 0) {
            $liveUid = $this->toInt($record['t3ver_oid'] ?? null);
            if ($liveUid > 0) {
                $pageUid = $this->resolveLiveRecordPid($table, $liveUid);
            }
        }

        return $pageUid;
    }

    /**
     * @return list<int>
     */
    public function getAccessibleWebMountPageUids(): array
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return [];
        }

        $mounts = [];
        foreach ($backendUser->getWebmounts() as $pageUid) {
            if ($pageUid > 0 && $this->canAccessPage($pageUid)) {
                $mounts[] = $pageUid;
            }
        }

        return array_values(array_unique($mounts));
    }

    public function isAdmin(): bool
    {
        return $this->getBackendUser()->isAdmin();
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new AccessDeniedException('backend user context', 'read');
        }

        return $backendUser;
    }

    private function resolveLiveRecordPid(string $table, int $uid): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $pid = $queryBuilder->select('pid')
                ->from($table)
                ->where($queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ))
                ->executeQuery()
                ->fetchOne();

            return is_numeric($pid) ? (int)$pid : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Resolve workspace versions and page translations to the default-language
     * live-facing row TYPO3 uses for permissions and web-mount rootlines.
     * Caller-provided row permissions are never trusted.
     *
     * @param int|array<string, mixed> $page
     * @return array<string, mixed>|null
     */
    private function resolvePermissionPageRow(int|array $page): ?array
    {
        $pageUid = is_array($page) ? $this->toInt($page['uid'] ?? null) : $page;
        if ($pageUid <= 0) {
            return null;
        }

        $row = $this->fetchPageRow($pageUid);
        if ($row === null) {
            return null;
        }

        $liveUid = $this->toInt($row['t3ver_oid'] ?? null);
        if ($liveUid > 0) {
            $liveRow = $this->fetchPageRow($liveUid);
            if ($liveRow !== null) {
                $row = $liveRow;
            }
        }

        $languageUid = $this->toInt($row['sys_language_uid'] ?? null);
        $translationParentUid = $this->toInt($row['l10n_parent'] ?? null);
        if ($languageUid !== 0 && $translationParentUid > 0) {
            return $this->fetchPageRow($translationParentUid);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageRow(int $pageUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());
        $row = $queryBuilder->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER),
            ))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
