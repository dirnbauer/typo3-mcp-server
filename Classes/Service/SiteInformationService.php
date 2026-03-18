<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service for centralizing site and domain information
 */
final class SiteInformationService
{
    protected ?ServerRequestInterface $currentRequest = null;

    public function __construct(
        protected readonly SiteFinder $siteFinder,
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

            // Check if the site has base variants (method may not exist in all TYPO3 versions)
            if (method_exists($site, 'getBaseVariants')) {
                foreach ($site->getBaseVariants() ?? [] as $variant) {
                    $variantHost = $variant->getBase()->getHost();
                    if (!empty($variantHost) && $variantHost !== '/' && !\in_array($variantHost, $domains)) {
                        $domains[] = $variantHost;
                    }
                }
            }
        }

        // If no domains found but we have a current request, try to use the host header
        if (empty($domains) && $this->currentRequest !== null) {
            $host = $this->currentRequest->getHeaderLine('Host');
            if (!empty($host)) {
                $domains[] = $host;
            }
        }

        $stringDomains = array_values(array_filter($domains, static fn(mixed $domain): bool => \is_string($domain) && $domain !== ''));
        /** @var list<string> $uniqueDomains */
        $uniqueDomains = array_values(array_unique($stringDomains));
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
            $slug = \is_scalar($page['slug'] ?? null) ? (string)$page['slug'] : '';
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

        if (\count($domains) === 1) {
            return 'Available domain: ' . $domains[0];
        }

        return 'Available domains: ' . implode(', ', $domains);
    }

    /**
     * Get host from current request
     *
     * @return string|null
     */
    protected function getHostFromRequest(): ?string
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
    protected function getPageRecord(int $pageId): ?array
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
