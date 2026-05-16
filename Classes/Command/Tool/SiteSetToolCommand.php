<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class SiteSetToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'SiteSet';
    }

    protected function shortDescription(): string
    {
        return 'Find, add, or remove TYPO3 Site Sets on an existing site.';
    }

    protected function exposedOptions(): array
    {
        return ['action', 'identifier', 'siteSet', 'query', 'includeHidden'];
    }
}
