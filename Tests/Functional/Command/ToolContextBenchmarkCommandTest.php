<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Command;

use Hn\McpServer\Command\ToolContextBenchmarkCommand;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\DevSiteTestTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

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
}
