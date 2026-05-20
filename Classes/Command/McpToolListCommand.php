<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\ToolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mcp:tool:list` — discover registered tools.
 *
 * Default output is a human-readable table. With `--json` the full schema for
 * every tool is dumped — useful for IDE/agent integration. With `--schema=NAME`
 * just one schema comes back, matching the equivalent MCP `tools/list` shape.
 */
final class McpToolListCommand extends Command
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List MCP tools registered in this TYPO3 instance.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON (machine-readable).')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text only (no decoration).')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Print the JSON Schema for one tool only and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tools = $this->toolRegistry->getTools();

        $schema = $input->getOption('schema');
        if (is_string($schema) && $schema !== '') {
            $tool = $this->toolRegistry->getTool($schema);
            if ($tool === null) {
                $output->writeln(json_encode(['ok' => false, 'error' => 'tool not found: ' . $schema]) ?: '{}');
                return Command::FAILURE;
            }
            $output->writeln(
                json_encode(['ok' => true, 'tool' => $schema, 'schema' => $tool->getSchema()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            );
            return Command::SUCCESS;
        }

        if ($input->getOption('json') === true) {
            $list = [];
            foreach ($tools as $name => $tool) {
                $list[$name] = $tool->getSchema();
            }
            $output->writeln(
                json_encode(['ok' => true, 'tools' => $list], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            );
            return Command::SUCCESS;
        }

        $plain = $input->getOption('plain') === true
            || ($input->hasOption('no-ansi') && $input->getOption('no-ansi') === true);
        ksort($tools);
        foreach ($tools as $name => $tool) {
            $description = '';
            $schemaArr = $tool->getSchema();
            if (isset($schemaArr['description']) && is_string($schemaArr['description'])) {
                $first = explode("\n", trim($schemaArr['description']))[0];
                $description = strlen($first) > 110 ? substr($first, 0, 107) . '…' : $first;
            }
            if ($plain) {
                $output->writeln(sprintf('%-26s  %s', $name, $description));
            } else {
                $output->writeln(sprintf('<info>%s</info>  %s', str_pad((string)$name, 26), $description));
            }
        }
        return Command::SUCCESS;
    }
}
