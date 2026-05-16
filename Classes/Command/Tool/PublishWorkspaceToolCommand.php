<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class PublishWorkspaceToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'PublishWorkspace';
    }

    protected function shortDescription(): string
    {
        return 'Publish workspace changes to live (defaults to dryRun=true).';
    }

    protected function exposedOptions(): array
    {
        return ['table', 'dryRun', 'workspace_id'];
    }
}
