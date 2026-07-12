<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\McpCliBackendUserBootstrapService;
use Mcp\Server\ServerRunner;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP Server Command - Uses logiscape/mcp-sdk-php
 */
final class McpServerCommand extends Command
{
    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly McpCliBackendUserBootstrapService $cliBackendUserBootstrap,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Start the MCP server for AI assistants');
        $this->setHelp('This command starts an MCP server that allows AI assistants to interact with TYPO3 via the stdio protocol.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->cliBackendUserBootstrap->initialize();

            // Ensure TCA is loaded using proper TYPO3 core method
            $tcaFactory = GeneralUtility::getContainer()->get(TcaFactory::class);
            if (!$tcaFactory instanceof TcaFactory) {
                throw new \RuntimeException('TcaFactory service is not available');
            }
            $GLOBALS['TCA'] = $tcaFactory->get();

            $debugToStderr = getenv('TYPO3_MCP_DEBUG_STDERR') !== false;
            $debug = $debugToStderr
                ? static function ($message) {
                    file_put_contents('php://stderr', '[MCP Server] ' . $message . PHP_EOL);
                }
            : null;

            $debug?->__invoke('Starting MCP server using logiscape/mcp-sdk-php');

            // Create the MCP server using the factory
            $server = $this->serverFactory->createServer($debug);

            $debug?->__invoke('All handlers registered, starting server...');

            // Create initialization options and run server
            $initOptions = $this->serverFactory->createInitializationOptions($server);
            $runner = new ServerRunner(
                $server,
                $initOptions,
                $debugToStderr ? null : new NullLogger(),
            );
            $runner->run();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Log the error to stderr, not stdout (to avoid corrupting MCP protocol)
            file_put_contents('php://stderr', 'MCP Server Error: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
            return Command::FAILURE;
        }
    }

}
