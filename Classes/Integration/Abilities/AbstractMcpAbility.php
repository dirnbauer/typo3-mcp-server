<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Service\BackendUserContextService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

/** @internal Loaded only when webconsulting/typo3-abilities is installed. */
abstract class AbstractMcpAbility extends AbstractAbility
{
    public function __construct(
        private readonly ?BackendUserContextService $backendUserContext = null,
    ) {}

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 'A real active TYPO3 backend user is required.';
        }
        $uid = $backendUser->user['uid'] ?? 0;
        if (!is_numeric($uid) || (int)$uid <= 0) {
            return 'A real active TYPO3 backend user is required.';
        }
        if ($context->surface === ExecutionContext::SURFACE_MCP) {
            // The MCP endpoint or CLI bootstrap already hydrated this user
            // through BackendUserContextService; hydrating again here would
            // reset the session's read-workspace selection mid-request.
            return true;
        }
        if ($this->backendUserContext === null) {
            return 'The TYPO3 backend-user context bootstrap is unavailable.';
        }

        try {
            $this->backendUserContext->initialize(
                $backendUser,
                $context->surface === ExecutionContext::SURFACE_REST,
            );
        } catch (AccessDeniedException) {
            return 'A real active TYPO3 backend user is required.';
        } catch (\Throwable) {
            return 'The TYPO3 backend-user context could not be initialized safely.';
        }

        return true;
    }
}
