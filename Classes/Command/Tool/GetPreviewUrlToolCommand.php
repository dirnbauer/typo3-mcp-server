<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetPreviewUrlToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetPreviewUrl';
    }

    protected function shortDescription(): string
    {
        return 'Build a workspace preview URL for a page or content element.';
    }

    protected function exposedOptions(): array
    {
        return ['table', 'uid', 'language'];
    }
}
