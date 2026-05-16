<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generic runner: `vendor/bin/typo3 mcp:tool <name> [--param k=v]`.
 *
 * Wraps any registered MCP tool by name. Use this for one-off scripting and
 * for tools that don't have a dedicated `mcp:<tool>` shortcut. The dedicated
 * commands (mcp:read-table, mcp:write-table, …) call the same machinery —
 * they only differ in being faster to type and self-describing in `--help`.
 */
final class McpToolRunCommand extends AbstractMcpToolCommand
{
    private string $resolvedName = '';

    protected function toolName(): string
    {
        return $this->resolvedName;
    }

    protected function shortDescription(): string
    {
        return 'Run any registered MCP tool by name (use mcp:tool:list to discover them).';
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('mcp:tool')
            ->addArgument(
                'tool',
                InputArgument::REQUIRED,
                'The MCP tool name (case-sensitive). Use `mcp:tool:list` to see what is available.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('tool');
        $this->resolvedName = is_string($name) ? $name : '';
        return parent::execute($input, $output);
    }
}
