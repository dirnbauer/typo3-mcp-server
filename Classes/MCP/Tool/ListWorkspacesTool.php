<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use stdClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ListWorkspacesTool extends AbstractTool
{
    public function __construct(
        private readonly WorkspaceContextService $workspaceContextService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'List all workspaces accessible to the current user. Shows which workspace is currently active. Use this to find the workspace_id for other tools.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
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
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new CallToolResult([new TextContent('No backend user session available.')], true);
        }

        $workspaces = $this->workspaceContextService->getAvailableWorkspaces($backendUser);

        if (empty($workspaces)) {
            return new CallToolResult([new TextContent(
                "No workspaces available.\n"
                . "A workspace will be created automatically when you use a write tool.",
            )]);
        }

        $lines = ["AVAILABLE WORKSPACES\n====================\n"];
        foreach ($workspaces as $ws) {
            $marker = $ws['active'] ? ' [ACTIVE]' : '';
            $lines[] = \sprintf(
                "- id=%d: %s (access: %s)%s%s",
                $ws['id'],
                $ws['title'],
                $ws['access'],
                $marker,
                $ws['description'] ? "\n  " . $ws['description'] : '',
            );
        }
        $lines[] = "\nUse workspace_id parameter on any tool to switch workspace.";

        return new CallToolResult([new TextContent(implode("\n", $lines))]);
    }
}
