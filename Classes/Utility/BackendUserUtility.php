<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The one place that turns `$GLOBALS['BE_USER']->user['uid']` into an int.
 */
final class BackendUserUtility
{
    /**
     * UID of the given backend user, 0 when none is authenticated.
     */
    public static function getUserId(?BackendUserAuthentication $backendUser): int
    {
        $uid = $backendUser?->user['uid'] ?? null;

        return is_numeric($uid) && (int)$uid > 0 ? (int)$uid : 0;
    }

    /**
     * UID of the current `$GLOBALS['BE_USER']`, 0 when none is authenticated.
     */
    public static function getCurrentUserId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return self::getUserId($backendUser instanceof BackendUserAuthentication ? $backendUser : null);
    }
}
