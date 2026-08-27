<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\AccessDeniedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Establishes the TYPO3 backend-user state required by optional Ability
 * projections without trusting the globals prepared by a transport adapter.
 */
final readonly class AbilityBackendUserContextService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private WorkspaceContextService $workspaceContextService,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * Revalidate and hydrate a backend user before an MCP Ability runs.
     *
     * REST bearer authentication is stateless, so it needs an anonymous
     * in-memory UserSession for DataHandler-adjacent code. A read workspace
     * context is initialized without creating a draft; concrete write paths
     * must still call WorkspaceContextService::switchToOptimalWorkspace().
     *
     * @return int selected read workspace id
     */
    public function initialize(
        BackendUserAuthentication $backendUser,
        bool $initializeAnonymousSession,
    ): int {
        $uid = $this->backendUserId($backendUser);
        $activeUser = $this->loadActiveBackendUser($uid);
        if ($activeUser === null) {
            throw new AccessDeniedException('active TYPO3 backend user', 'execute ability');
        }

        // Preserve an upstream workspace request only as an untrusted hint.
        // fetchGroupData() restores the persisted/default workspace first;
        // setTemporaryWorkspace() then accepts the hint only when TYPO3 grants it.
        $requestedWorkspace = $backendUser->workspace;
        $backendUser->user = $activeUser;
        $GLOBALS['BE_USER'] = $backendUser;

        if ($initializeAnonymousSession) {
            $backendUser->initializeUserSessionManager();
        }

        $this->resetPermissionState($backendUser);
        $backendUser->fetchGroupData();
        if ($requestedWorkspace > 0) {
            $backendUser->setTemporaryWorkspace($requestedWorkspace);
        }
        $this->hydrateUserConfiguration($backendUser, $activeUser);

        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $this->context->setAspect('backend.user', new UserAspect($backendUser));

        return $this->workspaceContextService->switchToReadWorkspace($backendUser);
    }

    private function backendUserId(BackendUserAuthentication $backendUser): int
    {
        $uid = $backendUser->user['uid'] ?? 0;
        if (!is_numeric($uid) || (int)$uid <= 0) {
            throw new AccessDeniedException('active TYPO3 backend user', 'execute ability');
        }

        return (int)$uid;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadActiveBackendUser(int $uid): ?array
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $queryBuilder = $connection->createQueryBuilder();
        $now = time();
        $row = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('starttime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->lte('starttime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function resetPermissionState(BackendUserAuthentication $backendUser): void
    {
        $backendUser->groupData = [
            'allowed_languages' => '',
            'tables_select' => '',
            'tables_modify' => '',
            'pagetypes_select' => '',
            'non_exclude_fields' => '',
            'explicit_allowdeny' => '',
            'custom_options' => '',
            'file_permissions' => '',
        ];
        $backendUser->userGroupsUID = [];
        $backendUser->userGroups = [];
        $backendUser->firstMainGroup = 0;
    }

    /**
     * Mirror TYPO3's backendSetUC() merge without persisting defaults during
     * a stateless REST request.
     *
     * @param array<string, mixed> $userData
     */
    private function hydrateUserConfiguration(BackendUserAuthentication $backendUser, array $userData): void
    {
        $storedUc = $this->decodeStoredUserConfiguration($userData['uc'] ?? null);
        $typo3Configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $backendConfiguration = is_array($typo3Configuration)
            ? ($typo3Configuration['BE'] ?? null)
            : null;
        $defaultUc = is_array($backendConfiguration)
            ? $this->normalizeStringKeyedArray($backendConfiguration['defaultUC'] ?? null)
            : [];
        $userTsConfig = $backendUser->getTSConfig();
        $setup = is_array($userTsConfig['setup.'] ?? null) ? $userTsConfig['setup.'] : [];
        $tsConfigDefaults = is_array($setup['default.'] ?? null)
            ? GeneralUtility::removeDotsFromTS($setup['default.'])
            : [];

        $backendUser->uc = array_merge(
            $backendUser->uc_default,
            $defaultUc,
            $tsConfigDefaults,
            $storedUc,
        );
        $backendUser->overrideUC();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStoredUserConfiguration(mixed $storedUc): array
    {
        if (is_array($storedUc)) {
            return $this->normalizeStringKeyedArray($storedUc);
        }
        if (!is_string($storedUc) || $storedUc === '') {
            return [];
        }

        try {
            // TYPO3 stores backend-user UC as serialized arrays; object hydration is disabled.
            // nosemgrep: php.lang.security.unserialize-use.unserialize-use
            $decoded = unserialize($storedUc, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        return $this->normalizeStringKeyedArray($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
