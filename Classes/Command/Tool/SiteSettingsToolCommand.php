<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class SiteSettingsToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'SiteSettings';
    }

    protected function shortDescription(): string
    {
        return 'List, read, or update TYPO3 site settings (dev-site only).';
    }

    protected function exposedOptions(): array
    {
        return ['action', 'identifier'];
    }
}
