<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\ViewHelperCatalogService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Return complete documentation for one Fluid ViewHelper.
 */
#[DevSiteOnly]
final class GetViewHelperDocumentationTool extends AbstractTool
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
            'description' => 'Return documentation for a specific Fluid ViewHelper (arguments and usage). '
                . 'Dev-site only (DDEV / localUnsafeMode). Use ListViewHelpers to discover tag names.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tagName' => [
                        'type' => 'string',
                        'description' => 'ViewHelper tag name, e.g. "f:for" or "f:link.page".',
                    ],
                ],
                'required' => ['tagName'],
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
        $tagName = is_string($params['tagName'] ?? null) ? trim($params['tagName']) : '';
        if ($tagName === '') {
            throw new ValidationException(['tagName is required.']);
        }

        $viewHelper = $this->viewHelperCatalogService->findByTagName($tagName);
        if ($viewHelper === null) {
            throw new ValidationException([
                'ViewHelper "' . $tagName . '" not found. Call ListViewHelpers to see available tags.',
            ]);
        }

        $text = "# {$viewHelper->tagName}\n\n"
            . "**XML Namespace:** {$viewHelper->xmlNamespace}\n\n"
            . "## Documentation\n\n{$viewHelper->documentation}";

        return new CallToolResult([new TextContent($text)]);
    }
}
