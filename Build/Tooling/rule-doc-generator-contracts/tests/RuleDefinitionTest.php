<?php

declare(strict_types=1);

namespace Webconsulting\RuleDocGeneratorContracts\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symplify\RuleDocGenerator\Exception\PoorDocumentationException;
use Symplify\RuleDocGenerator\Exception\ShouldNotHappenException;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RuleDefinitionTest extends TestCase
{
    public function testDefinitionProvidesExamplesAndNormalizedRuleMetadata(): void
    {
        $sample = new CodeSample('old()', 'new()');
        $definition = new RuleDefinition('Migrate a legacy call', [$sample]);
        $definition->setRuleClass('Example\\Migration\\LegacyCallRule');
        $definition->setRuleFilePath('/rules/LegacyCallRule.php');

        self::assertSame('Migrate a legacy call', $definition->getDescription());
        self::assertSame([$sample], $definition->getCodeSamples());
        self::assertSame('Example\\Migration\\LegacyCallRule', $definition->getRuleClass());
        self::assertSame('LegacyCallRule', $definition->getRuleShortClass());
        self::assertSame('rules/LegacyCallRule.php', $definition->getRuleFilePath());
        self::assertFalse($definition->isConfigurable());
    }

    public function testDefinitionRequiresAnExample(): void
    {
        $this->expectException(PoorDocumentationException::class);
        new RuleDefinition('Undocumented migration', []);
    }

    #[DataProvider('metadataAccessors')]
    public function testReadingUnsetRuleMetadataFailsExplicitly(string $method): void
    {
        $definition = new RuleDefinition('Migration', [new CodeSample('old()', 'new()')]);
        $this->expectException(ShouldNotHappenException::class);
        $definition->{$method}();
    }

    /** @return iterable<string, array{string}> */
    public static function metadataAccessors(): iterable
    {
        yield 'rule class' => ['getRuleClass'];
        yield 'short class' => ['getRuleShortClass'];
        yield 'file path' => ['getRuleFilePath'];
    }

    public function testConfigurationIsDetectedAmongMixedExamples(): void
    {
        $definition = new RuleDefinition('Configurable migration', [
            new CodeSample('before()', 'after()'),
            new ConfiguredCodeSample('old()', 'new()', ['enabled' => true]),
        ]);
        self::assertTrue($definition->isConfigurable());
    }
}
