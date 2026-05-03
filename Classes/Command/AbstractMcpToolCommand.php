<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Base class for "mcp:tool:<name>" Symfony console commands.
 *
 * Each per-tool command is a small concrete subclass that returns the MCP
 * tool name from {@see toolName()} and the JSON Schema property names it
 * wants to expose as Symfony --options. The base class:
 *
 *   1. Bootstraps an admin backend user + workspace context (mirrors
 *      McpTestCommand so the tool runs the way it does over MCP).
 *   2. Reads --options + --param key=value, builds the tool's input array,
 *      and calls the tool through the registry.
 *   3. Formats the result based on --json (raw JSON), --plain (text only,
 *      no ANSI), or default (pretty colored output).
 *
 * `--no-ansi` is automatically respected by Symfony Console; we only need to
 * make sure we don't emit colors that bypass the default OutputFormatter
 * when `--plain` is set.
 */
abstract class AbstractMcpToolCommand extends Command
{
    public function __construct(
        protected readonly ToolRegistry $toolRegistry,
        protected readonly TcaFactory $tcaFactory,
    ) {
        parent::__construct();
    }

    /**
     * MCP tool name (matches Tool->getName()).
     */
    abstract protected function toolName(): string;

    /**
     * One-line description shown in `vendor/bin/typo3 list mcp:tool`.
     */
    abstract protected function shortDescription(): string;

    /**
     * Optional list of property names from the tool's input schema that we
     * want to expose as named --options. Properties not listed here can still
     * be passed via repeated `--param key=value` (or --params <json>).
     *
     * @return list<string>
     */
    protected function exposedOptions(): array
    {
        return [];
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->shortDescription())
            ->addOption(
                'param',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Tool parameter as "key=value" or "key=@file.json". Repeatable.',
            )
            ->addOption(
                'params',
                null,
                InputOption::VALUE_REQUIRED,
                'JSON-encoded parameters object. Merged with --param entries (--param wins on conflict).',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Print the raw JSON tool result (no ANSI, machine-readable).',
            )
            ->addOption(
                'plain',
                null,
                InputOption::VALUE_NONE,
                'Print the tool result as plain text only (no decoration, no ANSI).',
            );

        foreach ($this->exposedOptions() as $name) {
            $this->addOption(
                $name,
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Shortcut for `--param %s=…`. See `mcp:tool:list --schema=%s` for the full schema.', $name, $this->toolName()),
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->bootstrapBackendUser();
            $this->ensureTcaLoaded();

            $tool = $this->toolRegistry->getTool($this->toolName());
            if ($tool === null) {
                $this->writeError($input, $output, sprintf('Tool "%s" is not registered.', $this->toolName()));
                return Command::FAILURE;
            }

            $params = $this->buildParams($input);
            $result = $tool->execute($params);

            return $this->renderResult($input, $output, $result);
        } catch (\Throwable $e) {
            $this->writeError($input, $output, $e->getMessage());
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildParams(InputInterface $input): array
    {
        /** @var array<string, mixed> $params */
        $params = [];

        $paramsJson = $input->getOption('params');
        if (is_string($paramsJson) && $paramsJson !== '') {
            $decoded = json_decode($paramsJson, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Invalid --params JSON: ' . json_last_error_msg());
            }
            /** @var array<string, mixed> $decoded */
            $params = $decoded;
        }

        foreach ($this->exposedOptions() as $name) {
            $value = $input->getOption($name);
            if ($value === null || $value === '') {
                continue;
            }
            $params[$name] = is_string($value)
                ? $this->coerceValue($value)
                : $value;
        }

        $paramArgs = $input->getOption('param');
        if (is_array($paramArgs)) {
            foreach ($paramArgs as $entry) {
                if (!is_string($entry) || $entry === '') {
                    continue;
                }
                $eq = strpos($entry, '=');
                if ($eq === false) {
                    throw new \InvalidArgumentException(sprintf('--param "%s" must be in key=value form.', $entry));
                }
                $key = substr($entry, 0, $eq);
                $rawValue = substr($entry, $eq + 1);
                $params[$key] = $this->coerceValue($rawValue);
            }
        }

        return $params;
    }

    /**
     * @return string|int|bool|float|array<int|string, mixed>|null
     */
    protected function coerceValue(string $value): string|int|bool|float|array|null
    {
        if ($value === '') {
            return '';
        }
        // @file.json — read JSON from a file. Path is constrained to the
        // TYPO3 project root so a sloppy operator can't accidentally smuggle
        // /etc/passwd into a tool param via a copy-pasted command line.
        if (str_starts_with($value, '@') && strlen($value) > 1) {
            $path = substr($value, 1);
            $resolved = realpath($path) ?: realpath(getcwd() . '/' . $path);
            if ($resolved === false || !is_file($resolved)) {
                throw new \InvalidArgumentException(sprintf('Param file "%s" not found.', $path));
            }
            $projectRoot = realpath(Environment::getProjectPath()) ?: '';
            if ($projectRoot === '' || !str_starts_with($resolved, $projectRoot . DIRECTORY_SEPARATOR) && $resolved !== $projectRoot) {
                throw new \InvalidArgumentException(sprintf(
                    'Param file "%s" must live under the TYPO3 project root (%s).',
                    $path,
                    $projectRoot,
                ));
            }
            $contents = file_get_contents($resolved);
            if ($contents === false) {
                throw new \InvalidArgumentException(sprintf('Could not read param file "%s".', $path));
            }
            $decoded = json_decode($contents, true);
            return is_array($decoded) ? $decoded : $contents;
        }
        // JSON literal
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '[', '"'], true)) {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if ($decoded === null || is_string($decoded) || is_int($decoded) || is_bool($decoded) || is_float($decoded) || is_array($decoded)) {
                    return $decoded;
                }
                return $value;
            }
        }
        // Booleans
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        // Numbers (only when they round-trip cleanly)
        if (is_numeric($value)) {
            if (ctype_digit(ltrim($value, '-'))) {
                return (int)$value;
            }
            return (float)$value;
        }
        return $value;
    }

    private function renderResult(InputInterface $input, OutputInterface $output, CallToolResult $result): int
    {
        $isError = $result->isError ?? false;
        $text = $this->extractText($result);
        $json = $input->getOption('json') === true;
        $plain = $input->getOption('plain') === true || $input->getOption('no-ansi') === true;

        if ($json) {
            // The MCP SDK already returns JSON-as-text; pretty-print if it's
            // valid JSON, otherwise emit as a JSON-escaped string so output
            // is always parseable.
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $payload = ['ok' => !$isError, 'result' => $decoded];
            } else {
                $payload = ['ok' => !$isError, 'result' => $text];
            }
            $output->writeln(
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            );
            return $isError ? Command::FAILURE : Command::SUCCESS;
        }

        if ($plain) {
            $output->writeln($text);
            return $isError ? Command::FAILURE : Command::SUCCESS;
        }

        if ($isError) {
            $output->writeln('<error>' . $text . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($text);
        return Command::SUCCESS;
    }

    private function extractText(CallToolResult $result): string
    {
        $out = '';
        foreach ($result->content as $item) {
            if ($item instanceof TextContent) {
                $out .= $item->text;
                continue;
            }
            $encoded = json_encode($item, JSON_PRETTY_PRINT);
            if (is_string($encoded)) {
                $out .= $encoded;
            }
        }
        return $out;
    }

    private function writeError(InputInterface $input, OutputInterface $output, string $message): void
    {
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(['ok' => false, 'error' => $message]) ?: '{}');
            return;
        }
        if ($input->getOption('plain') === true || $input->getOption('no-ansi') === true) {
            $output->writeln('Error: ' . $message);
            return;
        }
        $output->writeln('<error>' . $message . '</error>');
    }

    /**
     * Mirrors McpTestCommand::ensureAdminRights so direct CLI calls run with
     * the same workspace + user context the MCP HTTP transport sets up.
     */
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
        $defaults = $beUser->uc_default;
        $beUser->uc = array_merge(is_array($defaults) ? $defaults : [], is_array($beUser->uc) ? $beUser->uc : []);

        $workspace = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspace->switchToOptimalWorkspace($beUser);
    }

    private function ensureTcaLoaded(): void
    {
        /** @var array<string, mixed> $tca */
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $ttContent = is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : [];
        $columns = is_array($ttContent['columns'] ?? null) ? $ttContent['columns'] : [];
        if ($tca === [] || !isset($columns['pi_flexform'])) {
            $GLOBALS['TCA'] = $this->tcaFactory->get();
        }
    }
}
