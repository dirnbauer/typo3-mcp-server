<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\McpCliBackendUserBootstrapService;
use Hn\McpServer\Service\McpToolCatalogService;
use Hn\McpServer\Service\ToolContextBenchmarkService;
use Hn\McpServer\Service\ToolSchemaOptimizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;

/** Compare full/published tool catalogs and measure explicitly probed results. */
final class ToolContextBenchmarkCommand extends Command
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolSchemaOptimizer $schemaOptimizer,
        private readonly ToolContextBenchmarkService $benchmarkService,
        private readonly McpToolCatalogService $toolCatalog,
        private readonly DevSiteToolService $devSiteToolService,
        private readonly McpCliBackendUserBootstrapService $cliBackendUserBootstrap,
        private readonly TcaFactory $tcaFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('A/B benchmark full vs optimized MCP schemas and flag oversized tool responses.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON report.')
            ->addOption('schema-budget', null, InputOption::VALUE_REQUIRED, 'Per-tool optimized-schema byte budget.', '4096')
            ->addOption('response-budget', null, InputOption::VALUE_REQUIRED, 'Per-call serialized response byte budget.', '32768')
            ->addOption('bytes-per-token', null, InputOption::VALUE_REQUIRED, 'Approximate JSON bytes per token.', '2.42')
            ->addOption(
                'probe',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Read-only response probe as ToolName={"argument":"value"}; repeatable.',
            )
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Previous JSON report used to calculate deltas.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->devSiteToolService->assertAvailable();
            $this->cliBackendUserBootstrap->initialize();
            if (!is_array($GLOBALS['TCA'] ?? null) || $GLOBALS['TCA'] === []) {
                $GLOBALS['TCA'] = $this->tcaFactory->get();
            }
            $schemaBudget = $this->positiveInt($input->getOption('schema-budget'), 'schema-budget');
            $responseBudget = $this->positiveInt($input->getOption('response-budget'), 'response-budget');
            $bytesPerToken = $this->positiveFloat($input->getOption('bytes-per-token'), 'bytes-per-token');

            /** @var array<string, array<string, mixed>> $rawSchemas */
            $rawSchemas = [];
            /** @var array<string, array<string, mixed>> $optimizedSchemas */
            $optimizedSchemas = [];
            foreach ($this->toolRegistry->getTools() as $tool) {
                $name = $tool->getName();
                $rawSchemas[$name] = $tool->getSchema();
                $optimizedSchemas[$name] = $this->schemaOptimizer->optimize($rawSchemas[$name]);
            }

            $report = $this->benchmarkService->benchmarkSchemas(
                $rawSchemas,
                $optimizedSchemas,
                $schemaBudget,
                $bytesPerToken,
            );
            /** @var list<array{tool: string, bytes: int, estimatedTokens: int, budgetBytes: int, oversized: bool, isError: bool}> $responses */
            $responses = [];
            /** @var list<string> $oversizedResponses */
            $oversizedResponses = [];
            $probes = $input->getOption('probe');
            $probeRequests = [];
            foreach (is_array($probes) ? $probes : [] as $probe) {
                if (!is_string($probe)) {
                    continue;
                }
                [$toolName, $arguments] = $this->parseProbe($probe);
                $annotations = $rawSchemas[$toolName]['annotations'] ?? [];
                if (!is_array($annotations) || ($annotations['readOnlyHint'] ?? false) !== true) {
                    throw new \InvalidArgumentException('A probe must name an available tool with readOnlyHint=true: ' . $toolName);
                }
                if (isset($probeRequests[$toolName])) {
                    throw new \InvalidArgumentException('Use one probe per tool in each report: ' . $toolName);
                }
                $probeRequests[$toolName] = $arguments;
            }
            foreach ($probeRequests as $toolName => $arguments) {
                $result = $this->toolCatalog->execute($toolName, $arguments);
                $measurement = $this->benchmarkService->measureResponse(
                    $toolName,
                    $result->jsonSerialize(),
                    $responseBudget,
                    $bytesPerToken,
                );
                $measurement['isError'] = $result->isError === true;
                $responses[] = $measurement;
                if ($measurement['oversized']) {
                    $oversizedResponses[] = $toolName;
                }
            }
            $report['responses'] = $responses;
            $report['oversizedResponses'] = $oversizedResponses;

            $baseline = $input->getOption('baseline');
            if (is_string($baseline) && trim($baseline) !== '') {
                $baselineReport = $this->readBaseline($baseline);
                $report['baselineComparison'] = $this->benchmarkService->compareWithBaseline($report, $baselineReport);
            }
            $report['hint'] = $report['responses'] === []
                ? 'Add repeatable --probe=ToolName={} options to measure actual read-only result payloads.'
                : 'Store this JSON and pass it later with --baseline to detect schema or payload growth.';
        } catch (\Throwable $exception) {
            $payload = ['ok' => false, 'error' => $exception->getMessage()];
            if ($input->getOption('json') === true) {
                $errorJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $output->writeln($errorJson !== false ? $errorJson : '{}');
            } else {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');
            }

            return Command::FAILURE;
        }

        $exitCode = array_any($responses, static fn(array $response): bool => $response['isError'])
            ? Command::FAILURE : Command::SUCCESS;
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $exitCode;
        }

        $armA = $report['schema']['armAFull'];
        $armB = $report['schema']['armBOptimized'];
        $savings = $report['schema']['savings'];
        $output->writeln('<info>MCP tool-context benchmark</info>');
        $output->writeln(sprintf(
            'Schemas: arm A full %d bytes (~%d tokens); arm B optimized %d bytes (~%d tokens); saved %d bytes (%s%%).',
            $armA['bytes'],
            $armA['estimatedTokens'],
            $armB['bytes'],
            $armB['estimatedTokens'],
            $savings['bytes'],
            (string)$savings['percent'],
        ));
        $output->writeln('Oversized schemas: ' . ($report['oversizedSchemas'] === [] ? 'none' : implode(', ', $report['oversizedSchemas'])));
        foreach ($report['responses'] as $response) {
            $output->writeln(sprintf(
                'Response %s: %d bytes (~%d tokens)%s',
                $response['tool'],
                $response['bytes'],
                $response['estimatedTokens'],
                $response['oversized'] === true ? ' OVER BUDGET' : '',
            ));
        }

        return $exitCode;
    }

    /** @return array{string, array<string, mixed>} */
    private function parseProbe(string $probe): array
    {
        [$name, $json] = array_pad(explode('=', $probe, 2), 2, '{}');
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A probe must name a tool.');
        }
        $arguments = json_decode(trim($json) !== '' ? $json : '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($arguments) || (array_is_list($arguments) && $arguments !== [])) {
            throw new \InvalidArgumentException(sprintf('Probe arguments for %s must be a JSON object.', $name));
        }
        $normalizedArguments = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(sprintf('Probe arguments for %s must use string keys.', $name));
            }
            $normalizedArguments[$key] = $value;
        }

        return [$name, $normalizedArguments];
    }

    /** @return array<string, mixed> */
    private function readBaseline(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Baseline report is not a readable file: ' . $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('Baseline report could not be read: ' . $path);
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new \InvalidArgumentException('Baseline report must contain a JSON object.');
        }
        $report = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Baseline report must use string keys.');
            }
            $report[$key] = $value;
        }

        return $report;
    }

    private function positiveInt(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer) || $integer <= 0) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }

        return $integer;
    }

    private function positiveFloat(mixed $value, string $name): float
    {
        if (!is_numeric($value) || (float)$value <= 0) {
            throw new \InvalidArgumentException(sprintf('--%s must be greater than zero.', $name));
        }

        return (float)$value;
    }
}
