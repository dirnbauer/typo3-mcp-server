<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\PromptRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** CLI mirror of MCP prompts/list (the interoperable slash-command catalog). */
final class McpPromptListCommand extends Command
{
    public function __construct(
        private readonly PromptRegistry $promptRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List user-invocable MCP prompts / slash-command workflows.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the MCP prompt catalog as JSON.')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text only (no decoration).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompts = $this->promptRegistry->listPrompts()->prompts;
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(
                ['ok' => true, 'prompts' => $prompts],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            return Command::SUCCESS;
        }

        foreach ($prompts as $prompt) {
            $line = sprintf('/%-28s %s', $prompt->name, $prompt->description ?? '');
            $output->writeln($input->getOption('plain') === true ? $line : '<info>' . $line . '</info>');
        }
        return Command::SUCCESS;
    }
}
