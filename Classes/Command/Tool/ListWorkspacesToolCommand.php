<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class ListWorkspacesToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'ListWorkspaces';
    }

    protected function shortDescription(): string
    {
        return 'List workspaces accessible to the current backend user.';
    }
}
