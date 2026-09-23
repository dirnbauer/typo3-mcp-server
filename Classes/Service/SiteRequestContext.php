<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Publishes a PSR-7 request for tool calls that arrive without one.
 *
 * Core APIs behind DataHandler read $GLOBALS['TYPO3_REQUEST']: with
 * security.backend.htmlSanitizeRte enabled, the RTE sanitizer throws
 * without it, so rich text containing a t3:// link could not be saved.
 * Over HTTP the middleware publishes the real request; the CLI commands and
 * the stdio server (and any other caller outside the frontend request
 * handler) have none.
 *
 * The request represents the site the written record belongs to, resolved
 * the way TYPO3 resolves it: SiteFinder::getSiteByPageId() on the record's
 * page, that site's default language and its base. Only a record without a
 * page inside a site falls back to the first configured site; the returned
 * scope then carries a note the tool adds to its result.
 */
final readonly class SiteRequestContext
{
    private const string NO_SITE_BASE = 'http://localhost/';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * Publish a request for the site that owns $pageId. An active request
     * (HTTP) is kept, seen as a backend request. Leave the returned scope when
     * the write is done.
     *
     * @param int|null $pageId the page the written record lives on (for a
     *                         page record: the page itself); null or 0 when
     *                         the record has no page context
     */
    public function enterForPage(?int $pageId): SiteRequestScope
    {
        $activeRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($activeRequest instanceof ServerRequestInterface) {
            return $this->enterBackendViewOf($activeRequest);
        }

        $site = $this->findSiteForPage($pageId);
        $fallbackNote = null;
        if ($site === null) {
            // Without any site there is nothing to mix up: the request stands
            // for http://localhost/ and needs no note.
            $site = $this->findFirstSite();
            $fallbackNote = $site === null ? null : sprintf(
                '%s, so rich-text links were checked against the first site "%s" (%s).',
                $pageId !== null && $pageId > 0 ? sprintf('Page %d is not part of any site', $pageId) : 'The record has no page context',
                $site->getIdentifier(),
                (string)$site->getBase(),
            );
        }

        $request = $this->createRequest($site);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return SiteRequestScope::published($request, $site?->getIdentifier(), $fallbackNote);
    }

    /**
     * The /mcp endpoint answers inside the frontend middleware stack, so the
     * request it publishes says "frontend" - and FileRepository, storages and
     * the permission aspects then switch to frontend behaviour in the middle
     * of a backend-user DataHandler write (updating a live file reference of
     * a nested child in a workspace failed). The tools see the same request
     * as a backend request; the endpoint's request is restored afterwards.
     */
    private function enterBackendViewOf(ServerRequestInterface $activeRequest): SiteRequestScope
    {
        $applicationType = $activeRequest->getAttribute('applicationType');
        if (!is_int($applicationType)
            || ($applicationType & SystemEnvironmentBuilder::REQUESTTYPE_FE) !== SystemEnvironmentBuilder::REQUESTTYPE_FE
        ) {
            return SiteRequestScope::inactive();
        }

        $backendView = $activeRequest->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $backendView;

        return SiteRequestScope::replaced($backendView, $activeRequest);
    }

    /**
     * A GET request on the base of the site's default language with the
     * attributes core APIs read: normalizedParams, a backend
     * applicationType, site and language.
     */
    public function createRequest(?Site $site): ServerRequestInterface
    {
        $language = $site?->getDefaultLanguage();
        $base = $language?->getBase() ?? new Uri(self::NO_SITE_BASE);

        // A site base without a host ("/" or "/en/") means "the current
        // host", and a console process has none.
        $host = $base->getHost() !== '' ? $base->getHost() : 'localhost';
        $scheme = $base->getScheme() !== '' ? $base->getScheme() : ($host === 'localhost' ? 'http' : 'https');
        $port = $base->getPort();
        $path = $base->getPath() !== '' ? $base->getPath() : '/';
        $hostWithPort = $host . ($port !== null ? ':' . $port : '');

        $serverParams = [
            'HTTP_HOST' => $hostWithPort,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port ?? ($scheme === 'https' ? 443 : 80),
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $request = new ServerRequest(new Uri($scheme . '://' . $hostWithPort . $path), 'GET', 'php://input', [], $serverParams)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams))
            // The tools act as a backend user: FileRepository, storages and
            // permission aspects must keep their backend behaviour.
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE | SystemEnvironmentBuilder::REQUESTTYPE_CLI);

        if ($site !== null && $language !== null) {
            $request = $request
                ->withAttribute('site', $site)
                ->withAttribute('language', $language);
        }

        return $request;
    }

    private function findSiteForPage(?int $pageId): ?Site
    {
        if ($pageId === null || $pageId <= 0) {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    private function findFirstSite(): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            return $site;
        }

        return null;
    }
}
