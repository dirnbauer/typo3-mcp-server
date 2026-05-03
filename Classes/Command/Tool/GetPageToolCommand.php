<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetPageToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetPage';
    }

    protected function shortDescription(): string
    {
        return 'Fetch a TYPO3 page including content elements (workspace-aware).';
    }

    protected function exposedOptions(): array
    {
        return ['id', 'pageId', 'url', 'language'];
    }
}
