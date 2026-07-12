<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\McpCliBackendUserBootstrapService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;

/**
 * MCP Test Command - For testing MCP tools directly
 */
final class McpTestCommand extends Command
{
    /**
     * Constructor
     */
    public function __construct(
        protected ToolRegistry $toolRegistry,
        private readonly TcaFactory $tcaFactory,
        private readonly McpCliBackendUserBootstrapService $cliBackendUserBootstrap,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Test MCP tools directly')
            ->setHelp('This command allows you to test MCP tools directly without starting a server.')
            ->addArgument(
                'tool',
                InputArgument::REQUIRED,
                'The tool to test (e.g., "record/schema")',
            )
            ->addArgument(
                'params',
                InputArgument::OPTIONAL,
                'JSON-encoded parameters for the tool',
                '{}',
            );
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->cliBackendUserBootstrap->initialize();

            // Ensure TCA is loaded
            $this->ensureTcaLoaded();

            // Get command arguments
            $toolName = $input->getArgument('tool');
            $paramsJson = $input->getArgument('params');
            if (!is_string($toolName) || $toolName === '') {
                $output->writeln('<error>Tool argument must be a non-empty string.</error>');
                return Command::FAILURE;
            }
            if (!is_string($paramsJson)) {
                $output->writeln('<error>Parameters argument must be a JSON string.</error>');
                return Command::FAILURE;
            }

            // Parse parameters
            $decodedParams = json_decode($paramsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedParams)) {
                $output->writeln('<error>Invalid JSON parameters: ' . json_last_error_msg() . '</error>');
                return Command::FAILURE;
            }
            /** @var array<string, mixed> $params */
            $params = $decodedParams;

            // List all available tools if requested
            if ($toolName === 'list') {
                $output->writeln('<info>Available MCP tools:</info>');
                foreach ($this->toolRegistry->getTools() as $name => $tool) {
                    $output->writeln('- ' . $name);
                }
                return Command::SUCCESS;
            }

            // Find the tool
            $tool = $this->toolRegistry->getTool($toolName);
            if (!$tool) {
                $output->writeln('<error>Tool not found: ' . $toolName . '</error>');
                $output->writeln('<info>Available tools:</info>');
                foreach ($this->toolRegistry->getTools() as $name => $tool) {
                    $output->writeln('- ' . $name);
                }
                return Command::FAILURE;
            }

            // Execute the tool
            $output->writeln('<info>Executing tool: ' . $toolName . '</info>');
            $output->writeln('<info>Parameters: ' . $paramsJson . '</info>');
            $output->writeln('');

            $result = $tool->execute($params);

            // Display the result
            $output->writeln('<info>Result:</info>');

            // Check if the result is an error
            $isError = $result->isError ?? false;

            if ($isError) {
                $output->writeln('<error>Error: ' . $this->getResultText($result) . '</error>');
                return Command::FAILURE;
            }

            $output->writeln($this->getResultText($result));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            }
            return Command::FAILURE;
        }
    }

    /**
     * Extract text content from a CallToolResult
     */
    protected function getResultText(CallToolResult $result): string
    {
        $text = '';

        foreach ($result->content as $item) {
            if ($item instanceof TextContent) {
                $text .= $item->text;
            } else {
                $json = json_encode($item, JSON_PRETTY_PRINT);
                $text .= is_string($json) ? $json : '';
            }
        }

        return $text;
    }

    /**
     * Ensure TCA is loaded
     */
    protected function ensureTcaLoaded(): void
    {
        /** @var mixed $globalTca */
        $globalTca = $GLOBALS['TCA'] ?? null;
        $tca = is_array($globalTca) ? $globalTca : [];
        $ttContent = $tca['tt_content'] ?? null;
        $ttContentColumns = is_array($ttContent) && is_array($ttContent['columns'] ?? null)
            ? $ttContent['columns']
            : [];

        if ($tca === [] || !isset($ttContentColumns['pi_flexform'])) {
            $GLOBALS['TCA'] = $this->tcaFactory->get();
        }
    }
}
