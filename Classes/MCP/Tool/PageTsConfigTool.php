<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\PageAccessService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

/** Inspect merged Page TSconfig from the running TYPO3 instance. */
#[DevSiteOnly]
final class PageTsConfigTool extends AbstractTool
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PageAccessService $pageAccessService,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Resolve merged Page TSconfig for a page. Dev-site only; omit path for keys or use a dot-path for one branch.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Page UID; defaults to the first site root. Use 0 for global Page TSconfig.',
                        'minimum' => 0,
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => 'Dot-path such as TCEFORM.tt_content or mod.web_layout.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = is_numeric($params['pageId'] ?? null) ? (int)$params['pageId'] : $this->defaultPageId();
        if ($pageId === 0 && !$this->pageAccessService->isAdmin()) {
            throw new AccessDeniedException('global Page TSconfig', 'read');
        }
        if ($pageId > 0) {
            $this->pageAccessService->assertPageAccess($pageId, 'read Page TSconfig');
        }
        $path = is_string($params['path'] ?? null) ? trim($params['path'], ". \t\n\r\0\x0B") : '';

        try {
            $configuration = BackendUtility::getPagesTSconfig($pageId);
        } catch (\Throwable $exception) {
            throw new ValidationException([
                sprintf('Page TSconfig could not be resolved for page %d: %s', $pageId, $exception->getMessage()),
            ]);
        }
        /** @var array<string, mixed> $configuration */
        $payload = ['pageId' => $pageId];
        if ($path !== '') {
            $payload['path'] = $path;
            $payload['value'] = $this->valueAtPath($configuration, $path, $pageId);
        } else {
            $keys = [];
            foreach ($configuration as $key => $value) {
                $keys[rtrim((string)$key, '.')] = is_array($value) ? 'tree' : 'value';
            }
            ksort($keys);
            $payload['topLevelKeys'] = $keys;
            $payload['hint'] = $keys === []
                ? 'No Page TSconfig is active for this page.'
                : 'Pass path to retrieve one branch without returning the complete tree.';
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }

    private function defaultPageId(): int
    {
        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            throw new ValidationException(['No site is configured. Pass pageId=0 to inspect global Page TSconfig.']);
        }

        foreach ($sites as $site) {
            if ($this->pageAccessService->canAccessPage($site->getRootPageId())) {
                return $site->getRootPageId();
            }
        }

        throw new AccessDeniedException('configured site root', 'read Page TSconfig');
    }

    /** @param array<string, mixed> $configuration */
    private function valueAtPath(array $configuration, string $path, int $pageId): mixed
    {
        $value = $configuration;
        foreach (explode('.', $path) as $segment) {
            $key = is_array($value) && array_key_exists($segment . '.', $value) ? $segment . '.' : $segment;
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new ValidationException([
                    sprintf('Path "%s" was not found in Page TSconfig for page %d at segment "%s".', $path, $pageId, $segment),
                ]);
            }
            $value = $value[$key];
        }

        return $value;
    }
}
