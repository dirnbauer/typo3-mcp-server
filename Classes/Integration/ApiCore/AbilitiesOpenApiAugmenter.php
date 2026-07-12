<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\ApiCore;

use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/** @internal Adds exact registry contracts to sg_apicore's generic spec. */
final readonly class AbilitiesOpenApiAugmenter
{
    private const ABILITY_PREFIX = 'typo3-mcp/';

    private const ALLOWED_BASE_PATHS = [
        '/abilities',
        '/abilities/{namespace}/{name}',
        '/abilities/{namespace}/{name}/run',
        '/docs.json',
        '/docs/ui',
    ];

    public function __construct(
        private AbilitiesRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $specification
     * @return array<string, mixed>
     */
    public function augment(array $specification): array
    {
        $paths = $this->stringKeyedArray($specification['paths'] ?? null);
        $paths = array_intersect_key($paths, array_fill_keys(self::ALLOWED_BASE_PATHS, true));
        $genericRunPath = $this->stringKeyedArray($paths['/abilities/{namespace}/{name}/run'] ?? null);
        $genericRun = $this->stringKeyedArray($genericRunPath['post'] ?? null);

        $components = $this->stringKeyedArray($specification['components'] ?? null);
        $schemas = $this->stringKeyedArray($components['schemas'] ?? null);
        $abilityContracts = [];

        foreach ($this->registry->getDefinitions() as $definition) {
            if (!str_starts_with($definition->name, self::ABILITY_PREFIX)
                || !$definition->isExposedTo(ExecutionContext::SURFACE_REST)
            ) {
                continue;
            }

            $ability = $this->registry->get($definition->name);
            $schemaPrefix = $this->schemaPrefix($definition->name);
            $inputName = $schemaPrefix . 'Input';
            $outputName = $schemaPrefix . 'Output';
            $inputSchema = $ability->getInputSchema();
            $outputSchema = $ability->getOutputSchema();
            $schemas[$inputName] = $inputSchema !== [] ? $inputSchema : ['type' => 'object'];
            $schemas[$outputName] = $outputSchema !== [] ? $outputSchema : new \stdClass();

            $abilityContracts[] = $definition->toArray() + [
                'inputSchema' => ['$ref' => '#/components/schemas/' . $inputName],
                'outputSchema' => ['$ref' => '#/components/schemas/' . $outputName],
            ];

            $operation = $genericRun;
            unset($operation['parameters']);
            $operation['operationId'] = 'run_' . strtolower($schemaPrefix);
            $operation['summary'] = $definition->title;
            $operation['description'] = $definition->description;
            $operation['x-ability-name'] = $definition->name;
            $operation['x-required-scopes'] = $definition->scopes;
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $inputName],
                    ],
                ],
            ];
            $responses = $this->stringKeyedArray($operation['responses'] ?? null);
            $responses['200'] = [
                'description' => 'Ability executed successfully',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['ok', 'data'],
                            'properties' => [
                                'ok' => ['type' => 'boolean', 'enum' => [true]],
                                'data' => ['$ref' => '#/components/schemas/' . $outputName],
                            ],
                        ],
                    ],
                ],
            ];
            $operation['responses'] = $responses;
            $paths['/abilities/' . $definition->name . '/run'] = ['post' => $operation];
        }

        usort(
            $abilityContracts,
            static fn(array $a, array $b): int => strcmp(
                is_string($a['name'] ?? null) ? $a['name'] : '',
                is_string($b['name'] ?? null) ? $b['name'] : '',
            ),
        );
        ksort($paths, SORT_STRING);
        ksort($schemas, SORT_STRING);

        $components['schemas'] = $schemas;
        $specification['paths'] = $paths;
        $specification['components'] = $components;
        $specification['x-typo3-abilities'] = $abilityContracts;

        return $specification;
    }

    private function schemaPrefix(string $abilityName): string
    {
        $splitParts = preg_split('/[^a-z0-9]+/i', $abilityName);
        $parts = $splitParts !== false ? $splitParts : [];
        return implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
