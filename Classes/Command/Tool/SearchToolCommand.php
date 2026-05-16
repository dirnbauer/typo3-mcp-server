<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class SearchToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'Search';
    }

    protected function shortDescription(): string
    {
        return 'Full-text search across workspace-capable TCA tables.';
    }

    protected function exposedOptions(): array
    {
        return ['query', 'table', 'pageId', 'language', 'limit'];
    }
}
