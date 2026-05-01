<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for centralizing site and domain information
 */
final class SiteInformationService
{
    private ?ServerRequestInterface $currentRequest = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Set the current HTTP request for context
     */
    public function setCurrentRequest(?ServerRequestInterface $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Turn a possibly-relative URL into an absolute one.
     *
     * Order of preference: scheme/host of the active HTTP request, then the
     * first configured site whose base carries a host, then TYPO3's
     * TYPO3_REQUEST_HOST environment value. Returns the input unchanged when
     * none of those resolve a host (e.g. CLI/stdio without a configured site)
     * — better an honest relative URL than an invented one.
     *
     * @param string|null $url Anything from a relative path ("fileadmin/x.jpg")
     *                         to a leading-slash path ("/x.jpg") to an already
     *                         absolute URL.
     */
    public function makeAbsoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        // Already absolute (http://, https://, data:, etc.)
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }

        $base = $this->resolveRequestOrSiteBase();
        if ($base === null) {
            return $url;
        }

        return $base . '/' . ltrim($url, '/');
    }

    /**
     * Returns "<scheme>://<host>" from the active request, or the first site
     * whose base has a host, or TYPO3_REQUEST_HOST as a last resort.
     */
    private function resolveRequestOrSiteBase(): ?string
    {
        if ($this->currentRequest !== null) {
            $uri = $this->currentRequest->getUri();
            $host = $uri->getHost() ?: $this->currentRequest->getHeaderLine('Host');
            $scheme = $uri->getScheme() ?: 'https';
            if (!empty($host)) {
                return $scheme . '://' . $host;
            }
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteBase = $site->getBase();
                if ($siteBase->getHost() !== '') {
                    $scheme = $siteBase->getScheme() ?: 'https';
                    return $scheme . '://' . $siteBase->getHost();
                }
            }
        } catch (\Throwable) {
            // Ignore and fall through to the env-based fallback
        }

        $envHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        if (is_string($envHost) && $envHost !== '') {
            // TYPO3_REQUEST_HOST is "<scheme>://<host>" — accept only when the host
            // segment is actually populated, otherwise we'd build URLs like
            // "http:///fileadmin/x.jpg" in CLI/test contexts without a server name.
            $parts = parse_url($envHost);
            if (!empty($parts['host'])) {
                return $envHost;
            }
        }
        return null;
    }

    /**
     * Get all configured domains from TYPO3 sites
     *
     * @return list<string> Array of unique domain names
     */
    public function getAllDomains(): array
    {
        $sites = $this->siteFinder->getAllSites();
        $domains = [];

        foreach ($sites as $site) {
            $base = $site->getBase();
            $host = $base->getHost();

            // Add main domain if it's not empty and not just a path
            if (!empty($host) && $host !== '/') {
                $domains[] = $host;
            }
        }

        // If no domains found but we have a current request, try to use the host header
        if (empty($domains) && $this->currentRequest !== null) {
            $host = $this->currentRequest->getHeaderLine('Host');
            if (!empty($host)) {
                $domains[] = $host;
            }
        }

        /** @var list<string> $uniqueDomains */
        $uniqueDomains = array_values(array_unique($domains));
        return $uniqueDomains;
    }

    /**
     * Generate URL for a page with multiple fallback strategies
     *
     * @param int $pageId The page ID
     * @param int $languageId The language ID (default: 0)
     * @return string|null The generated URL or null if generation fails
     */
    public function generatePageUrl(int $pageId, int $languageId = 0): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);

            // Get the appropriate language
            try {
                $language = $languageId > 0 ? $site->getLanguageById($languageId) : $site->getDefaultLanguage();
            } catch (\Throwable) {
                // Fall back to default language if specified language not found
                $language = $site->getDefaultLanguage();
            }

            // Generate the URI
            $uri = $site->getRouter()->generateUri($pageId, ['_language' => $language]);
            $generatedUrl = (string)$uri;

            // Check if the generated URL is missing a host (e.g., just a path like "/page")
            if ($generatedUrl !== '' && !str_starts_with($generatedUrl, 'http')) {
                // Try to add host from site configuration
                $host = $site->getBase()->getHost();

                // If site base is just "/" or empty, try to get host from current request
                if ($host === '' || $host === '/') {
                    $host = $this->getHostFromRequest();
                }

                if (!empty($host)) {
                    // Determine scheme
                    $scheme = 'https';
                    if ($this->currentRequest !== null) {
                        $scheme = $this->currentRequest->getUri()->getScheme() ?: 'https';
                    }

                    // Build full URL
                    $generatedUrl = $scheme . '://' . $host . $generatedUrl;
                }
            }

            return $generatedUrl;
        } catch (\Throwable) {
            // Continue to fallback strategies
        }

        // Fallback: Try to get page record and build URL from slug
        try {
            $page = $this->getPageRecord($pageId);
            $slug = is_scalar($page['slug'] ?? null) ? (string)$page['slug'] : '';
            if ($page !== null && $slug !== '') {
                $host = $this->getHostFromRequest();
                if (!empty($host)) {
                    $scheme = 'https';
                    if ($this->currentRequest !== null) {
                        $scheme = $this->currentRequest->getUri()->getScheme() ?: 'https';
                    }
                    return $scheme . '://' . $host . $slug;
                }

                // If no host available, return just the slug
                return $slug;
            }
        } catch (\Throwable) {
            // Ignore and return null
        }

        return null;
    }

    /**
     * Get formatted text listing all available domains for tool descriptions
     *
     * @return string Formatted text describing available domains
     */
    public function getAvailableDomainsText(): string
    {
        $domains = $this->getAllDomains();

        if (empty($domains)) {
            return 'No specific domains configured. Use page IDs or relative paths.';
        }

        if (count($domains) === 1) {
            return 'Available domain: ' . $domains[0];
        }

        return 'Available domains: ' . implode(', ', $domains);
    }

    /**
     * Get host from current request
     *
     * @return string|null
     */
    private function getHostFromRequest(): ?string
    {
        if ($this->currentRequest === null) {
            return null;
        }

        // Try Host header first
        $host = $this->currentRequest->getHeaderLine('Host');
        if (!empty($host)) {
            return $host;
        }

        // Try to get from URI
        $uri = $this->currentRequest->getUri();
        $host = $uri->getHost();
        if (!empty($host)) {
            return $host;
        }

        return null;
    }

    /**
     * Get page record by ID
     *
     * @param int $pageId
     * @return array<string, mixed>|null
     */
    private function getPageRecord(int $pageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $page = $queryBuilder
            ->select('uid', 'slug', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $page ?: null;
    }
}
