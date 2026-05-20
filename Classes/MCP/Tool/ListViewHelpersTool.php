<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\ViewHelperCatalogService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * List Fluid ViewHelpers available in the current Composer project.
 */
#[DevSiteOnly]
final class ListViewHelpersTool extends AbstractTool
{
    public function __construct(
        private readonly ViewHelperCatalogService $viewHelperCatalogService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'List Fluid ViewHelpers by tag name and XML namespace. '
                . 'Dev-site only (DDEV / localUnsafeMode). Read-only reference for template work.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $viewHelpers = [];
        foreach ($this->viewHelperCatalogService->getAllViewHelpers() as $viewHelper) {
            $viewHelpers[] = [
                'tagName' => $viewHelper->tagName,
                'xmlNamespace' => $viewHelper->xmlNamespace,
            ];
        }

        return new CallToolResult([new TextContent(
            json_encode([
                'total' => count($viewHelpers),
                'viewHelpers' => $viewHelpers,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
        )]);
    }
}
