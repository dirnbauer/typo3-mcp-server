<?php

declare(strict_types=1);

namespace Webconsulting\RuleDocGeneratorContracts\Tests;

use PHPUnit\Framework\TestCase;
use Symplify\RuleDocGenerator\Contract\Category\CategoryInfererInterface;
use Symplify\RuleDocGenerator\Contract\CodeSampleInterface;
use Symplify\RuleDocGenerator\Contract\ConfigurableRuleInterface;
use Symplify\RuleDocGenerator\Contract\DocumentedRuleInterface;
use Symplify\RuleDocGenerator\Contract\RuleCodeSamplePrinterInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class ContractConsumerTest extends TestCase
{
    public function testExistingRuleCategoryAndPrinterImplementationsRemainInteroperable(): void
    {
        $rule = new class () implements DocumentedRuleInterface, ConfigurableRuleInterface {
            public function getRuleDefinition(): RuleDefinition
            {
                return new RuleDefinition('Legacy migration', [new CodeSample('old()', 'new()')]);
            }
        };
        $category = new class () implements CategoryInfererInterface {
            public function infer(RuleDefinition $ruleDefinition): ?string
            {
                return $ruleDefinition->getDescription() === 'Legacy migration' ? 'Migration' : null;
            }
        };
        $printer = new class () implements RuleCodeSamplePrinterInterface {
            public function isMatch(string $class): bool
            {
                return is_a($class, CodeSampleInterface::class, true);
            }

            public function print(CodeSampleInterface $codeSample, RuleDefinition $ruleDefinition): array
            {
                return [$ruleDefinition->getDescription(), $codeSample->getBadCode(), $codeSample->getGoodCode()];
            }
        };

        $definition = $this->definitionFor($rule);
        $sample = $definition->getCodeSamples()[0];
        self::assertSame('Migration', $this->inferCategory($category, $definition));
        self::assertNull($this->inferCategory($category, new RuleDefinition('Other', [new CodeSample('a()', 'b()')])));
        self::assertSame(['Legacy migration', 'old()', 'new()'], $this->renderSample($printer, $sample, $definition));
        self::assertSame([], $this->renderSample($printer, $sample, $definition, RuleDefinition::class));
    }

    private function definitionFor(DocumentedRuleInterface&ConfigurableRuleInterface $rule): RuleDefinition
    {
        return $rule->getRuleDefinition();
    }

    private function inferCategory(CategoryInfererInterface $category, RuleDefinition $definition): ?string
    {
        return $category->infer($definition);
    }

    /** @return string[] */
    private function renderSample(RuleCodeSamplePrinterInterface $printer, CodeSampleInterface $sample, RuleDefinition $definition, ?string $sampleClass = null): array
    {
        return $printer->isMatch($sampleClass ?? $sample::class) ? $printer->print($sample, $definition) : [];
    }
}
