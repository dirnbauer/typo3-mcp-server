<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class WriteTableToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'WriteTable';
    }

    protected function shortDescription(): string
    {
        return 'Create / update / move / translate / delete a TCA record (workspace-staged).';
    }

    protected function exposedOptions(): array
    {
        return ['action', 'table', 'pid', 'uid', 'position'];
    }
}
