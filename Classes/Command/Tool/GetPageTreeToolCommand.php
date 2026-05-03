<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetPageTreeToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetPageTree';
    }

    protected function shortDescription(): string
    {
        return 'Print the page tree as text (workspace-aware).';
    }

    protected function exposedOptions(): array
    {
        return ['rootId', 'depth', 'language'];
    }
}
