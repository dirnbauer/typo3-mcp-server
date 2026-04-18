<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Ensures backend user preferences contain TYPO3's required defaults.
 *
 * Older or partially written UC payloads can miss keys like "titleLen", which
 * TYPO3 backend rendering accesses directly. Merge defaults early in every
 * backend request and persist the repaired payload once if needed.
 */
final class BackendUserConfigurationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && !empty($backendUser->user['uid'])) {
            $normalizedUc = array_merge($backendUser->uc_default, $backendUser->uc);
            if ($normalizedUc != $backendUser->uc) {
                $backendUser->uc = $normalizedUc;
                $backendUser->writeUC();
            }
        }

        return $handler->handle($request);
    }
}
