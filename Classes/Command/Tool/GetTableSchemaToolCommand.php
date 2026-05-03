<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetTableSchemaToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetTableSchema';
    }

    protected function shortDescription(): string
    {
        return 'Return the TCA-derived schema for a table (fields, types, validation).';
    }

    protected function exposedOptions(): array
    {
        return ['table', 'type'];
    }
}
