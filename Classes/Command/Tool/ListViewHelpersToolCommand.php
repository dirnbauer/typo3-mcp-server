<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class ListViewHelpersToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'ListViewHelpers';
    }

    protected function shortDescription(): string
    {
        return 'List Fluid ViewHelpers (dev-site only).';
    }
}
