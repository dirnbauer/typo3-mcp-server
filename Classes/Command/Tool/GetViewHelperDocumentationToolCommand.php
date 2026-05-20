<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetViewHelperDocumentationToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetViewHelperDocumentation';
    }

    protected function shortDescription(): string
    {
        return 'Get Fluid ViewHelper documentation (dev-site only).';
    }

    protected function exposedOptions(): array
    {
        return ['tagName'];
    }
}
