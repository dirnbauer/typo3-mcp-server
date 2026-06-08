<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\Service;

use Hn\McpServer\Service\ToolSchemaOptimizer;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ToolSchemaOptimizerTest extends TestCase
{
    private function createOptimizer(string|null|false $schemaDetail): ToolSchemaOptimizer
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        if ($schemaDetail === false) {
            $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not set'));
        } else {
            $config = $schemaDetail === null ? [] : ['schemaDetail' => $schemaDetail];
            $extensionConfiguration->method('get')->willReturn($config);
        }

        return new ToolSchemaOptimizer($extensionConfiguration);
    }

    public function testConciseIsDefaultWhenSettingMissing(): void
    {
        self::assertTrue($this->createOptimizer(null)->isConcise());
    }

    public function testConciseIsDefaultWhenConfigUnreadable(): void
    {
        self::assertTrue($this->createOptimizer(false)->isConcise());
    }

    public function testFullModeDisablesCondensing(): void
    {
        $optimizer = $this->createOptimizer('full');
        self::assertFalse($optimizer->isConcise());

        $longDescription = str_repeat('This is a long sentence that should not be touched. ', 10);
        $schema = ['description' => $longDescription, 'inputSchema' => ['type' => 'object', 'properties' => []]];

        self::assertSame($schema, $optimizer->optimize($schema));
    }

    public function testShortDescriptionsStayIntact(): void
    {
        $schema = [
            'description' => 'Legacy tool description',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['value' => ['type' => 'string', 'description' => 'A value.']],
            ],
        ];

        $optimized = $this->createOptimizer('concise')->optimize($schema);

        self::assertSame('Legacy tool description', $optimized['description']);
        self::assertSame('A value.', $optimized['inputSchema']['properties']['value']['description']);
    }

    public function testLongDescriptionIsTrimmedWithEllipsis(): void
    {
        $description = 'Create or update records. '
            . 'This paragraph explains a lot of background detail that goes well beyond the budget and keeps going. '
            . 'It continues with even more prose about edge cases that an LLM rarely needs up front. '
            . 'And still more filler text to be sure we exceed the configured character budget comfortably.';

        $optimized = $this->createOptimizer('concise')->optimize(['description' => $description]);

        self::assertStringStartsWith('Create or update records.', $optimized['description']);
        self::assertStringEndsWith('…', $optimized['description']);
        self::assertLessThan(mb_strlen($description), mb_strlen($optimized['description']));
    }

    public function testCriticalSentenceIsPreservedEvenWhenLate(): void
    {
        $description = 'Update a record. '
            . 'Here is a bunch of leading prose that fills up the available character budget so the critical note '
            . 'would otherwise be dropped from the condensed output entirely. '
            . 'On update the array REPLACES all children.';

        $optimized = $this->createOptimizer('concise')->optimize(['description' => $description]);

        self::assertStringContainsString('REPLACES all children', $optimized['description']);
    }

    public function testStructuralKeywordsAreUntouched(): void
    {
        $schema = [
            'description' => 'Write records. ' . str_repeat('Extra prose sentence here. ', 20),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['create', 'update', 'delete'],
                        'description' => 'The action.',
                    ],
                ],
                'required' => ['action'],
            ],
        ];

        $optimized = $this->createOptimizer('concise')->optimize($schema);

        self::assertSame(['create', 'update', 'delete'], $optimized['inputSchema']['properties']['action']['enum']);
        self::assertSame(['action'], $optimized['inputSchema']['required']);
        self::assertSame('object', $optimized['inputSchema']['type']);
    }
}
