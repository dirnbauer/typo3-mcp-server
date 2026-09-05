<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/** Describe the current caller through the same guards used by record tools. */
final readonly class BackendUserSummaryService
{
    public function __construct(
        private TableAccessService $tableAccessService,
        private PageAccessService $pageAccessService,
    ) {}

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return ['authenticated' => false];
        }
        $user = $backendUser->user;
        $uid = is_numeric($user['uid'] ?? null) ? (int)$user['uid'] : 0;
        if ($uid <= 0) {
            return ['authenticated' => false];
        }

        $isAdmin = $backendUser->isAdmin();
        $mounts = $this->pageAccessService->getAccessibleWebMountPageUids();
        $tablePermissions = [];
        foreach (['pages', 'tt_content', 'sys_file_reference'] as $table) {
            $readAccess = $this->tableAccessService->getTableAccessInfo($table, false);
            $writeAccess = $this->tableAccessService->getTableAccessInfo($table);
            $tablePermissions[$table] = [
                'read' => $readAccess['accessible'] && $readAccess['permissions']['read'],
                'write' => $writeAccess['accessible'] && $writeAccess['permissions']['write'],
            ];
        }

        return [
            'authenticated' => true,
            'uid' => $uid,
            'username' => is_string($user['username'] ?? null) ? $user['username'] : '',
            'isAdmin' => $isAdmin,
            'workspaceId' => $backendUser->workspace,
            'pageAccess' => [
                'scope' => $isAdmin ? 'all' : ($mounts === [] ? 'none' : 'mounted'),
                'mountPageIds' => $mounts,
            ],
            'tablePermissions' => $tablePermissions,
            'hint' => 'Table-level permissions only; page, field, file, workspace and capability checks still apply to each call. '
                . 'workspaceId is the current context; strict-mode writes select a draft automatically. Use ListTables for other tables.',
        ];
    }
}
