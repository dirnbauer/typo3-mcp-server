<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\ResourceRegistry;
use Mcp\Types\TextResourceContents;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * CLI mirror for MCP TCA resources (`typo3-mcp:///tca` and `typo3-mcp:///tca/{table}`).
 */
final class TcaResourceCommand extends Command
{
    public function __construct(
        private readonly ResourceRegistry $resourceRegistry,
        private readonly TcaFactory $tcaFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Read TYPO3 TCA reference markdown (dev-site only). Mirrors MCP resources typo3-mcp:///tca.')
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Table name for a single-table schema. Omit for the accessible-tables overview.',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Wrap output in a {ok, result} JSON envelope.')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text only (no decoration).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->bootstrapBackendUser();
            $this->ensureTcaLoaded();

            if (!$this->resourceRegistry->isAvailable()) {
                return $this->writeError(
                    $input,
                    $output,
                    'TCA resources are only available in DDEV / local development mode. '
                    . 'Enable localUnsafeMode=on or run inside DDEV / TYPO3 Development context.',
                );
            }

            $table = $input->getOption('table');
            $uri = is_string($table) && $table !== ''
                ? ResourceRegistry::URI_TABLE_PREFIX . $table
                : ResourceRegistry::URI_OVERVIEW;

            $result = $this->resourceRegistry->readResource($uri);
            $text = '';
            foreach ($result->contents as $content) {
                if ($content instanceof TextResourceContents) {
                    $text = $content->text;
                    break;
                }
            }

            return $this->renderText($input, $output, $text);
        } catch (\Throwable $e) {
            return $this->writeError($input, $output, $e->getMessage());
        }
    }

    private function renderText(InputInterface $input, OutputInterface $output, string $text): int
    {
        if ($input->getOption('json') === true) {
            $encoded = json_encode(['ok' => true, 'result' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $output->writeln($encoded !== false ? $encoded : '{}');
            return Command::SUCCESS;
        }

        if ($input->getOption('plain') === true
            || ($input->hasOption('no-ansi') && $input->getOption('no-ansi') === true)) {
            $output->writeln($text);
            return Command::SUCCESS;
        }

        $output->writeln($text);
        return Command::SUCCESS;
    }

    private function writeError(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json') === true) {
            $encoded = json_encode(['ok' => false, 'error' => $message]);
            $output->writeln($encoded !== false ? $encoded : '{}');
            return Command::FAILURE;
        }

        if ($input->getOption('plain') === true
            || ($input->hasOption('no-ansi') && $input->getOption('no-ansi') === true)) {
            $output->writeln('Error: ' . $message);
            return Command::FAILURE;
        }

        $output->writeln('<error>' . $message . '</error>');
        return Command::FAILURE;
    }

    private function bootstrapBackendUser(): void
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $beUser->user = ['admin' => 1, 'uid' => 1];
            $GLOBALS['BE_USER'] = $beUser;
        }
        if (!$beUser->isAdmin()) {
            $beUser->user['admin'] = 1;
            $beUser->user['uid'] ??= 1;
        }
    }

    private function ensureTcaLoaded(): void
    {
        /** @var array<string, mixed> $tca */
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        if ($tca === []) {
            $GLOBALS['TCA'] = $this->tcaFactory->get();
        }
    }
}
