<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class ListTablesToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'ListTables';
    }

    protected function shortDescription(): string
    {
        return 'List MCP-accessible TCA tables and their access info.';
    }
}
