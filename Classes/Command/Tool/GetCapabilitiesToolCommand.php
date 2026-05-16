<?php

declare(strict_types=1);

namespace Hn\McpServer\Command\Tool;

use Hn\McpServer\Command\AbstractMcpToolCommand;

final class GetCapabilitiesToolCommand extends AbstractMcpToolCommand
{
    protected function toolName(): string
    {
        return 'GetCapabilities';
    }

    protected function shortDescription(): string
    {
        return 'Show the active capability manifest + runtime mode (DDEV/local-mode).';
    }
}
