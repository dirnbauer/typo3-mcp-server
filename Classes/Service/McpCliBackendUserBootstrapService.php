<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Boots MCP console entry points with TYPO3's real database-backed `_cli_`
 * user and the same session/context invariants used by REST Abilities.
 */
final readonly class McpCliBackendUserBootstrapService
{
    public function __construct(
        private AbilityBackendUserContextService $backendUserContext,
    ) {}

    public function initialize(): BackendUserAuthentication
    {
        if (!Environment::isCli()) {
            throw new \RuntimeException('MCP CLI backend-user bootstrap is only available in CLI mode.');
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid = $backendUser instanceof BackendUserAuthentication
            ? ($backendUser->user['uid'] ?? 0)
            : 0;

        if (!is_numeric($uid) || (int)$uid <= 0) {
            if (!$backendUser instanceof CommandLineUserAuthentication) {
                $backendUser = Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
            }
            Bootstrap::initializeBackendAuthentication();
            $backendUser = $GLOBALS['BE_USER'] ?? null;
        }

        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('TYPO3 did not initialize a CLI backend user.');
        }

        $this->backendUserContext->initialize($backendUser, true);

        return $backendUser;
    }
}
