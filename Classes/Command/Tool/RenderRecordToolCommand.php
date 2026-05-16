<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class RenderRecordToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'RenderRecord';
    }

    protected function shortDescription(): string
    {
        return 'Render a page (or single content element) through the FE in workspace context.';
    }

    protected function exposedOptions(): array
    {
        return ['pageId', 'contentUid', 'mode', 'language', 'maxLength'];
    }
}
