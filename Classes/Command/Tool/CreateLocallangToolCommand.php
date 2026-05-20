<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class CreateLocallangToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'CreateLocallang';
    }

    protected function shortDescription(): string
    {
        return 'Create or extend XLF language files (dev-site only).';
    }

    protected function exposedOptions(): array
    {
        return ['extensionKey', 'fileName', 'extensionBasePath'];
    }
}
