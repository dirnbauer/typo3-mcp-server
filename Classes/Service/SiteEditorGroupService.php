<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provisions a dedicated backend group per website so non-admin editors can edit
 * the new site *without* granting access to every existing editor team.
 *
 * This is the standard TYPO3 multi-site permission pattern: one ``be_group``
 * mounted at the site root page, carrying the table / page-type / module access
 * and content-element permissions an editor needs. Editors gain access purely by
 * being members of that group — no other team is touched. The group can be seeded
 * with named editors on creation, or populated by an admin later.
 *
 * Page-tree edit permission is wired by making the new group the owner-group of
 * the root page (``perms_groupid`` + ``perms_group``). To stay non-destructive on
 * a pre-existing root page, ownership is only set when the page has no group owner
 * yet (or when the root page was just created by CreateSite).
 *
 * ``be_groups`` / ``be_users`` are not workspace-versioned; writes run through
 * DataHandler as admin in the live workspace. ``pages.perms_*`` are system columns
 * outside TCA, so they are written directly.
 */
final readonly class SiteEditorGroupService
{
    /**
     * Tables a content editor needs to work in the Page module.
     */
    private const EDITOR_TABLES = 'pages,tt_content';

    /**
     * Doktypes editors may create: standard, link, shortcut, spacer, folder.
     */
    private const EDITOR_PAGETYPES = '1,3,4,199,254';

    /**
     * Backend modules editors need: Page (web_layout) and List (web_list).
     */
    private const EDITOR_MODULES = 'web_layout,web_list';

    /**
     * Full page permission bitmask (show + edit page + delete + new + edit content).
     */
    private const PERMS_ALL = 31;

    public function __construct(
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    /**
     * Ensure a dedicated editor group exists for the given site root and wire up
     * its access. Idempotent: an existing group with the same title is reused.
     *
     * @param list<string> $editors usernames to add to the group
     * @return array{
     *     group: array{id: int, title: string, created: bool, mountpoint: int},
     *     pagePermissions: array{pageId: int, granted: bool, reason: string},
     *     editors: array{added: list<string>, skipped: array<string, int>}
     * }|null null when nothing could be provisioned (invalid input / no backend user)
     */
    public function ensureEditorGroup(
        int $rootPageId,
        string $siteLabel,
        bool $rootPageIsNew,
        array $editors = [],
        ?BackendUserAuthentication $beUser = null,
    ): ?array {
        if ($rootPageId <= 0) {
            return null;
        }
        $beUser ??= ($GLOBALS['BE_USER'] ?? null);
        if (!$beUser instanceof BackendUserAuthentication) {
            return null;
        }

        $title = $this->groupTitle($siteLabel, $rootPageId);
        $group = $this->findOrCreateGroup($title, $rootPageId, $beUser);
        if ($group === null) {
            return null;
        }

        $pagePermissions = $this->grantPagePermissions($rootPageId, $group['id'], $rootPageIsNew);
        $editorReport = $this->addEditors($editors, $group['id'], $beUser);

        return [
            'group' => $group + ['mountpoint' => $rootPageId],
            'pagePermissions' => $pagePermissions,
            'editors' => $editorReport,
        ];
    }

    /**
     * @return array{id: int, title: string, created: bool}|null
     */
    private function findOrCreateGroup(string $title, int $rootPageId, BackendUserAuthentication $beUser): ?array
    {
        $existingId = $this->findGroupByTitle($title);
        if ($existingId !== null) {
            return ['id' => $existingId, 'title' => $title, 'created' => false];
        }

        $newId = 'NEW' . bin2hex(random_bytes(8));
        $data = [
            'be_groups' => [
                $newId => [
                    'pid' => 0,
                    'title' => $title,
                    'db_mountpoints' => (string)$rootPageId,
                    'tables_select' => self::EDITOR_TABLES,
                    'tables_modify' => self::EDITOR_TABLES,
                    'pagetypes_select' => self::EDITOR_PAGETYPES,
                    'groupMods' => self::EDITOR_MODULES,
                    'explicit_allowdeny' => $this->buildContentTypeAllowList(),
                ],
            ],
        ];

        $dataHandler = $this->processAsAdminInLive($beUser, $data);
        $uid = is_numeric($dataHandler->substNEWwithIDs[$newId] ?? null) ? (int)$dataHandler->substNEWwithIDs[$newId] : 0;
        if ($uid <= 0 || $dataHandler->errorLog !== []) {
            $this->logger->warning('Failed to create site editor group', ['title' => $title, 'errors' => $dataHandler->errorLog]);
            return null;
        }

        return ['id' => $uid, 'title' => $title, 'created' => true];
    }

    /**
     * Build the ``explicit_allowdeny`` allow-list for every registered tt_content
     * content type, so editors can add the content elements the install offers.
     *
     * ``tt_content.CType`` uses ``authMode = explicitAllow``: without this, an
     * editor group could add no content element at all. The stored format is a
     * CSV of ``table:field:value`` tokens (see BackendUserAuthentication::checkAuthMode()).
     */
    private function buildContentTypeAllowList(): string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        $ttContent = is_array($tca) && is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : [];
        $columns = is_array($ttContent['columns'] ?? null) ? $ttContent['columns'] : [];
        $cType = is_array($columns['CType'] ?? null) ? $columns['CType'] : [];
        $config = is_array($cType['config'] ?? null) ? $cType['config'] : [];
        $items = is_array($config['items'] ?? null) ? $config['items'] : [];

        $tokens = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Support associative (v12+) and legacy indexed item definitions.
            $value = $item['value'] ?? $item[1] ?? null;
            if (!is_string($value) || $value === '' || $value === '--div--') {
                continue;
            }
            $tokens[] = 'tt_content:CType:' . $value;
        }

        return implode(',', array_values(array_unique($tokens)));
    }

    /**
     * Make the editor group the owner-group of the root page so its members may
     * edit content there. Non-destructive: an existing group owner on a
     * pre-existing root page is preserved.
     *
     * @return array{pageId: int, granted: bool, reason: string}
     */
    private function grantPagePermissions(int $rootPageId, int $groupId, bool $rootPageIsNew): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        try {
            $currentOwner = $connection->select(['perms_groupid'], 'pages', ['uid' => $rootPageId])->fetchOne();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read page permissions', ['page' => $rootPageId, 'exception' => $e]);
            return ['pageId' => $rootPageId, 'granted' => false, 'reason' => 'readFailed'];
        }
        $currentOwner = is_numeric($currentOwner) ? (int)$currentOwner : 0;

        if ($currentOwner === $groupId) {
            return ['pageId' => $rootPageId, 'granted' => true, 'reason' => 'alreadyGranted'];
        }
        if ($currentOwner !== 0 && !$rootPageIsNew) {
            // Page already owned by another group; don't override an existing setup.
            return ['pageId' => $rootPageId, 'granted' => false, 'reason' => 'pageOwnedByOtherGroup'];
        }

        try {
            $connection->update(
                'pages',
                ['perms_groupid' => $groupId, 'perms_group' => self::PERMS_ALL],
                ['uid' => $rootPageId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to grant page permissions', ['page' => $rootPageId, 'exception' => $e]);
            return ['pageId' => $rootPageId, 'granted' => false, 'reason' => 'writeFailed'];
        }

        return ['pageId' => $rootPageId, 'granted' => true, 'reason' => 'granted'];
    }

    /**
     * Add the named non-admin backend users to the editor group.
     *
     * @param list<string> $editors
     * @return array{added: list<string>, skipped: array<string, int>}
     */
    private function addEditors(array $editors, int $groupId, BackendUserAuthentication $beUser): array
    {
        $report = ['added' => [], 'skipped' => []];
        $datamap = [];

        foreach ($editors as $username) {
            if (!is_string($username) || trim($username) === '') {
                continue;
            }
            $username = trim($username);
            $user = $this->findBackendUserByUsername($username);
            if ($user === null) {
                $this->recordSkip($report['skipped'], 'notFound');
                continue;
            }
            if ($user['admin'] === 1) {
                $this->recordSkip($report['skipped'], 'admin');
                continue;
            }
            $groups = $this->parseIntList($user['usergroup']);
            if (in_array($groupId, $groups, true)) {
                $this->recordSkip($report['skipped'], 'alreadyMember');
                continue;
            }
            $datamap[$user['uid']] = implode(',', [...$groups, $groupId]);
            $report['added'][] = $username;
        }

        if ($datamap !== []) {
            $data = ['be_users' => []];
            foreach ($datamap as $uid => $usergroup) {
                $data['be_users'][$uid] = ['usergroup' => $usergroup];
            }
            $dataHandler = $this->processAsAdminInLive($beUser, $data);
            if ($dataHandler->errorLog !== []) {
                $this->logger->warning('Failed to add editors to group', ['group' => $groupId, 'errors' => $dataHandler->errorLog]);
            }
        }

        return $report;
    }

    private function findGroupByTitle(string $title): ?int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
            $queryBuilder->getRestrictions()->removeAll();
            $uid = $queryBuilder
                ->select('uid')
                ->from('be_groups')
                ->where(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
                )
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Throwable) {
            return null;
        }

        return is_numeric($uid) && (int)$uid > 0 ? (int)$uid : null;
    }

    /**
     * @return array{uid: int, admin: int, usergroup: mixed}|null
     */
    private function findBackendUserByUsername(string $username): ?array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('uid', 'admin', 'usergroup')
                ->from('be_users')
                ->where(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row) || !is_numeric($row['uid'] ?? null)) {
            return null;
        }

        return [
            'uid' => (int)$row['uid'],
            'admin' => is_numeric($row['admin'] ?? null) ? (int)$row['admin'] : 0,
            'usergroup' => $row['usergroup'] ?? '',
        ];
    }

    /**
     * Run a DataHandler datamap as admin in the live workspace, restoring the
     * caller's context afterwards. Mirrors WorkspaceContextService::createMcpWorkspace().
     * The returned DataHandler carries ``errorLog`` and ``substNEWwithIDs``.
     *
     * @param array<string, array<int|string, array<string, mixed>>> $data
     */
    private function processAsAdminInLive(BackendUserAuthentication $beUser, array $data): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $originalAdmin = $beUser->user['admin'] ?? 0;
        $originalWorkspace = $beUser->workspace;
        $originalWorkspaceId = $beUser->user['workspace_id'] ?? 0;

        $beUser->user['admin'] = 1;
        $beUser->workspace = 0;
        $beUser->user['workspace_id'] = 0;

        try {
            $dataHandler->start($data, []);
            $dataHandler->process_datamap();
        } finally {
            $beUser->user['admin'] = $originalAdmin;
            $beUser->workspace = $originalWorkspace;
            $beUser->user['workspace_id'] = $originalWorkspaceId;
        }

        return $dataHandler;
    }

    /**
     * @param array<string, int> $skipped
     */
    private function recordSkip(array &$skipped, string $reason): void
    {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    }

    /**
     * @return list<int>
     */
    private function parseIntList(mixed $value): array
    {
        if (!is_string($value) && !is_int($value)) {
            return [];
        }
        $out = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $out[] = (int)$part;
        }

        return $out;
    }

    private function groupTitle(string $siteLabel, int $rootPageId): string
    {
        $label = trim($siteLabel);
        if ($label === '') {
            $label = 'page ' . $rootPageId;
        }

        return 'Editors: ' . $label;
    }
}
