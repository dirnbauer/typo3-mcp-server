<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\PromptRegistry;
use Mcp\Types\TextContent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** CLI mirror of MCP prompts/get. */
final class McpPromptGetCommand extends Command
{
    public function __construct(
        private readonly PromptRegistry $promptRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Render one MCP prompt / slash-command workflow.')
            ->addArgument('name', InputArgument::REQUIRED, 'Prompt name, for example typo3-content-edit.')
            ->addOption('request', null, InputOption::VALUE_REQUIRED, 'Current user request passed into the workflow.')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Optional page, record, language, or editorial context.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the MCP prompt result as JSON.')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text only (no decoration).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || $name === '') {
            return Command::INVALID;
        }

        $arguments = [];
        foreach (['request', 'context'] as $option) {
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                $arguments[$option] = $value;
            }
        }

        try {
            $result = $this->promptRegistry->getPrompt($name, $arguments);
        } catch (\Throwable $e) {
            $output->writeln($input->getOption('json') === true
                ? json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR)
                : '<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(
                ['ok' => true, 'prompt' => $result],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            return Command::SUCCESS;
        }

        foreach ($result->messages as $message) {
            if ($message->content instanceof TextContent) {
                $output->writeln($message->content->text);
            }
        }
        return Command::SUCCESS;
    }
}
