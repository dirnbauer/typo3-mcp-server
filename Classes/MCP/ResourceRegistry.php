<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\TcaResourceFormatter;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\TextResourceContents;

/**
 * MCP resources exposed on dev sites (DDEV / local development mode).
 */
final readonly class ResourceRegistry
{
    public const URI_OVERVIEW = 'typo3-mcp://tca';
    public const URI_TABLE_PREFIX = 'typo3-mcp://tca/';

    public function __construct(
        private TcaResourceFormatter $tcaResourceFormatter,
        private DevSiteToolService $devSiteToolService,
    ) {}

    public function isAvailable(): bool
    {
        return $this->devSiteToolService->isAvailable();
    }

    public function listResources(): ListResourcesResult
    {
        $this->devSiteToolService->assertAvailable();

        return new ListResourcesResult([
            new Resource(
                name: 'typo3_tca_overview',
                uri: self::URI_OVERVIEW,
                description: 'Overview of TYPO3 database tables accessible to the current backend user.',
                mimeType: 'text/markdown',
            ),
        ]);
    }

    public function listResourceTemplates(): ListResourceTemplatesResult
    {
        $this->devSiteToolService->assertAvailable();

        return new ListResourceTemplatesResult([
            new ResourceTemplate(
                name: 'typo3_tca_table',
                uriTemplate: self::URI_TABLE_PREFIX . '{tableName}',
                description: 'Detailed TCA configuration for one accessible table.',
                mimeType: 'text/markdown',
            ),
        ]);
    }

    public function readResource(string $uri): ReadResourceResult
    {
        $this->devSiteToolService->assertAvailable();

        if ($uri === self::URI_OVERVIEW) {
            return new ReadResourceResult([
                new TextResourceContents(
                    uri: $uri,
                    text: $this->tcaResourceFormatter->renderOverview(),
                    mimeType: 'text/markdown',
                ),
            ]);
        }

        if (str_starts_with($uri, self::URI_TABLE_PREFIX)) {
            $tableName = substr($uri, strlen(self::URI_TABLE_PREFIX));
            if ($tableName === '' || preg_match('/^[a-z0-9_]+$/', $tableName) !== 1) {
                throw new \InvalidArgumentException('Invalid TCA resource URI: ' . $uri);
            }

            return new ReadResourceResult([
                new TextResourceContents(
                    uri: $uri,
                    text: $this->tcaResourceFormatter->renderTable($tableName),
                    mimeType: 'text/markdown',
                ),
            ]);
        }

        throw new \InvalidArgumentException('Unknown resource URI: ' . $uri);
    }
}
