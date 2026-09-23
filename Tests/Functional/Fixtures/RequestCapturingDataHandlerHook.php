<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * DataHandler processDatamapClass hook that records the request active while
 * a datamap is processed - the one the RTE sanitizer reads.
 */
final class RequestCapturingDataHandlerHook
{
    /** @var list<array{site: string|null, host: string, backend: bool}> */
    public static array $captured = [];

    public static function reset(): void
    {
        self::$captured = [];
    }

    public function processDatamap_beforeStart(): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            self::$captured[] = ['site' => null, 'host' => '', 'backend' => false];
            return;
        }

        $site = $request->getAttribute('site');
        self::$captured[] = [
            'site' => $site instanceof Site ? $site->getIdentifier() : null,
            'host' => $request->getUri()->getHost(),
            'backend' => ApplicationType::fromRequest($request)->isBackend(),
        ];
    }
}
