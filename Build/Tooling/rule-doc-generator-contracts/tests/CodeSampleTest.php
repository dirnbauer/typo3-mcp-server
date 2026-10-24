<?php

declare(strict_types=1);

namespace Webconsulting\RuleDocGeneratorContracts\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symplify\RuleDocGenerator\Contract\CodeSampleInterface;
use Symplify\RuleDocGenerator\Exception\ShouldNotHappenException;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ComposerJsonAwareCodeSample;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ExtraFileCodeSample;

final class CodeSampleTest extends TestCase
{
    public function testEverySampleVariantKeepsNormalizedBeforeAndAfterCode(): void
    {
        $samples = [
            new CodeSample("  old()\n", "\nnew()  "),
            new ConfiguredCodeSample("  old()\n", "\nnew()  ", ['target' => 'new']),
            new ComposerJsonAwareCodeSample("  old()\n", "\nnew()  ", '{"require":{"php":"^8.1"}}'),
            new ExtraFileCodeSample("  old()\n", "\nnew()  ", 'services.yaml'),
        ];

        foreach ($samples as $sample) {
            $this->assertNormalizedSample($sample);
        }
    }

    private function assertNormalizedSample(CodeSampleInterface $sample): void
    {
        self::assertSame('old()', $sample->getBadCode());
        self::assertSame('new()', $sample->getGoodCode());
    }

    #[DataProvider('invalidSamples')]
    public function testAmbiguousOrEmptyExamplesAreRejected(string $before, string $after): void
    {
        $this->expectException(ShouldNotHappenException::class);
        new CodeSample($before, $after);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSamples(): iterable
    {
        yield 'empty before' => ['', 'new()'];
        yield 'whitespace before' => [" \n\t", 'new()'];
        yield 'empty after' => ['old()', ''];
        yield 'whitespace after' => ['old()', " \n\t"];
        yield 'unchanged' => ['same()', 'same()'];
        yield 'unchanged after trimming' => ['same() ', ' same()'];
    }

    public function testConfiguredExamplePreservesBothNumericAndNamedOptions(): void
    {
        $configuration = [0 => 'first', 'nested' => ['enabled' => false, 'count' => 0]];
        $sample = new ConfiguredCodeSample('old()', 'new()', $configuration);
        self::assertSame($configuration, $sample->getConfiguration());
    }

    public function testEmptyConfigurationIsRejected(): void
    {
        $this->expectException(ShouldNotHappenException::class);
        new ConfiguredCodeSample('old()', 'new()', []);
    }

    public function testSupplementaryFilesRemainAvailableToDocumentationConsumers(): void
    {
        $composerJson = "{\n  \"require\": {\"php\": \"^8.1\"}\n}";
        self::assertSame($composerJson, (new ComposerJsonAwareCodeSample('old()', 'new()', $composerJson))->getComposerJson());
        self::assertSame('fixture.php', (new ExtraFileCodeSample('old()', 'new()', 'fixture.php'))->getExtraFile());
    }
}
