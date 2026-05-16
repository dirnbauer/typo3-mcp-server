<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class ReadTableToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'ReadTable';
    }

    protected function shortDescription(): string
    {
        return 'Read records from a TCA table (workspace-aware, language-aware).';
    }

    protected function exposedOptions(): array
    {
        return ['table', 'pid', 'uid', 'limit', 'offset', 'language'];
    }
}
