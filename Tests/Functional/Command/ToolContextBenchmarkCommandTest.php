<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Command;

use Hn\McpServer\Command\ToolContextBenchmarkCommand;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\McpCliBackendUserBootstrapService;
use Hn\McpServer\Service\McpToolCatalogService;
use Hn\McpServer\Service\ToolContextBenchmarkService;
use Hn\McpServer\Service\ToolResultNormalizer;
use Hn\McpServer\Service\ToolSchemaOptimizer;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ToolContextBenchmarkCommandTest extends AbstractFunctionalTest
{
    use DevSiteTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDevSiteTools();
    }

    public function testJsonReportComparesSchemaArmsAndFlagsAProbedPayload(): void
    {
        $tester = new CommandTester($this->getService(ToolContextBenchmarkCommand::class));

        $exitCode = $tester->execute([
            '--json' => true,
            '--schema-budget' => '1',
            '--response-budget' => '1',
            '--probe' => ['ApplicationInfo={}'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(40, $payload['schema']['toolCount']);
        self::assertGreaterThanOrEqual(
            $payload['schema']['armBOptimized']['bytes'],
            $payload['schema']['armAFull']['bytes'],
        );
        self::assertContains('ApplicationInfo', $payload['oversizedResponses']);
        self::assertSame('ApplicationInfo', $payload['responses'][0]['tool']);
        self::assertTrue($payload['responses'][0]['oversized']);
        self::assertNotEmpty($payload['oversizedSchemas']);
    }

    public function testWriteProbesAreRejectedBeforeExecution(): void
    {
        $tester = new CommandTester($this->getService(ToolContextBenchmarkCommand::class));
        $exitCode = $tester->execute(['--json' => true, '--probe' => ['WriteTable={}']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('readOnlyHint=true', $tester->getDisplay());
    }

    public function testDuplicateProbesCannotOverwriteBaselineMeasurements(): void
    {
        $tester = new CommandTester($this->getService(ToolContextBenchmarkCommand::class));
        $exitCode = $tester->execute(['--json' => true, '--probe' => [
            'ApplicationInfo={}', 'ApplicationInfo={"packages":true}',
        ]]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('one probe per tool', $tester->getDisplay());
    }

    public function testFailedToolProbeReturnsFailureAlongsideTheReport(): void
    {
        $tester = new CommandTester($this->getService(ToolContextBenchmarkCommand::class));
        $exitCode = $tester->execute(['--json' => true, '--probe' => ['ReadTable={}']]);

        self::assertSame(Command::FAILURE, $exitCode);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['responses'][0]['isError']);
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputPreventsAllProbes(array $options): void
    {
        $tool = $this->createMock(AbstractTool::class);
        $tool->method('getName')->willReturn('ApplicationInfo');
        $tool->method('getSchema')->willReturn([
            'inputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
        ]);
        $tool->expects($this->never())->method('execute');
        $registry = new ToolRegistry([$tool]);
        $command = new ToolContextBenchmarkCommand(
            $registry,
            new ToolSchemaOptimizer(),
            new ToolContextBenchmarkService(),
            new McpToolCatalogService($registry, new ToolResultNormalizer()),
            $this->getService(DevSiteToolService::class),
            new McpCliBackendUserBootstrapService(new BackendUserContextService(
                GeneralUtility::makeInstance(ConnectionPool::class),
                GeneralUtility::makeInstance(Context::class),
                $this->getService(WorkspaceContextService::class),
                GeneralUtility::makeInstance(LanguageServiceFactory::class),
            )),
            $this->getService(TcaFactory::class),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute($options + [
            '--json' => true,
            '--probe' => ['ApplicationInfo={}'],
        ]));
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'unreadable baseline' => [['--baseline' => __DIR__ . '/missing-baseline.json']];
        yield 'non-finite token ratio' => [['--bytes-per-token' => '1e999']];
        yield 'later unknown tool' => [['--probe' => ['ApplicationInfo={}', 'UnknownTool={}']]];
        yield 'later malformed JSON' => [['--probe' => ['ApplicationInfo={}', 'ReadTable={']]];
        yield 'duplicate probe' => [['--probe' => ['ApplicationInfo={}', 'ApplicationInfo={}']]];
    }
}
