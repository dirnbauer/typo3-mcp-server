<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class ApplyShadcnPresetToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'ApplyShadcnPreset';
    }

    protected function shortDescription(): string
    {
        return 'Apply a shadcn/ui preset to an existing frontend project.';
    }

    protected function exposedOptions(): array
    {
        return ['preset', 'only', 'cwd', 'packageManager'];
    }
}
