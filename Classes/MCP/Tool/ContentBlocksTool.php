<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** Inspect optional friendsoftypo3/content-blocks runtime definitions. */
#[DevSiteOnly]
final class ContentBlocksTool extends AbstractTool
{
    private const REGISTRY_CLASS = 'TYPO3\\CMS\\ContentBlocks\\Registry\\ContentBlockRegistry';

    public function getSchema(): array
    {
        return [
            'description' => 'List registered Content Blocks or inspect one block, including its generated type, table, package, and YAML fields.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Content Block name including vendor, for example myvendor/teaser.',
                    ],
                    'typeName' => [
                        'type' => 'string',
                        'description' => 'Generated type name, such as the CType.',
                    ],
                    'table' => [
                        'type' => 'string',
                        'default' => 'tt_content',
                        'description' => 'Table used with typeName.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        /** @var class-string<object> $registryClass */
        $registryClass = self::REGISTRY_CLASS;
        if (!class_exists($registryClass)) {
            return $this->result([
                'available' => false,
                'contentBlockCount' => 0,
                'hint' => 'Install and activate friendsoftypo3/content-blocks to expose Content Block definitions.',
            ]);
        }

        $registry = GeneralUtility::makeInstance($registryClass);

        $name = is_string($params['name'] ?? null) ? trim($params['name']) : '';
        $typeName = is_string($params['typeName'] ?? null) ? trim($params['typeName']) : '';
        $table = is_string($params['table'] ?? null) ? trim($params['table']) : 'tt_content';
        $table = $table !== '' ? $table : 'tt_content';

        if ($name !== '') {
            if ($this->call($registry, 'hasContentBlock', $name) !== true) {
                throw new ValidationException([
                    sprintf('No Content Block "%s" is registered.', $name),
                ]);
            }
            $contentBlock = $this->call($registry, 'getContentBlock', $name);
            if (!is_object($contentBlock)) {
                throw new ValidationException([sprintf('Content Block "%s" could not be loaded.', $name)]);
            }

            return $this->result(['available' => true, 'contentBlock' => $this->describe($contentBlock, true)]);
        }

        if ($typeName !== '') {
            $contentBlock = $this->call($registry, 'getByTypeName', $table, $typeName);
            if (!is_object($contentBlock)) {
                throw new ValidationException([
                    sprintf('No Content Block with typeName "%s" is registered for table "%s".', $typeName, $table),
                ]);
            }

            return $this->result(['available' => true, 'contentBlock' => $this->describe($contentBlock, true)]);
        }

        $all = $this->call($registry, 'getAll');
        if (!is_iterable($all)) {
            throw new ValidationException(['The Content Blocks registry returned an unsupported result.']);
        }
        $contentBlocks = [];
        foreach ($all as $contentBlock) {
            if (!is_object($contentBlock)) {
                continue;
            }
            $contentBlocks[$this->stringValue($this->call($contentBlock, 'getName'))] = $this->describe($contentBlock, false);
        }
        ksort($contentBlocks, SORT_STRING);

        return $this->result([
            'available' => true,
            'contentBlockCount' => count($contentBlocks),
            'contentBlocks' => $contentBlocks,
            'hint' => $contentBlocks === []
                ? 'Content Blocks is installed, but no block is registered.'
                : 'Pass name or typeName to retrieve the full field definitions of one block.',
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(object $contentBlock, bool $detailed): array
    {
        $yaml = $this->call($contentBlock, 'getYaml');
        $yaml = is_array($yaml) ? $yaml : [];
        $contentType = $this->stringValue($this->call($contentBlock, 'getContentType'));
        $description = array_filter([
            'contentType' => $contentType,
            'table' => $yaml['table'] ?? null,
            'typeName' => $yaml['typeName'] ?? null,
            'title' => $this->translate($this->nullableString($yaml['title'] ?? null)),
            'hostExtension' => $this->call($contentBlock, 'getHostExtension'),
            'fieldCount' => is_array($yaml['fields'] ?? null) ? count($yaml['fields']) : 0,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        if (!$detailed) {
            return $description;
        }

        $prefixType = $this->call($contentBlock, 'getPrefixType');

        return array_filter([
            'name' => $this->call($contentBlock, 'getName'),
            ...$description,
            'vendor' => $this->call($contentBlock, 'getVendor'),
            'package' => $this->call($contentBlock, 'getPackage'),
            'extPath' => $this->call($contentBlock, 'getExtPath'),
            'description' => $this->translate($this->nullableString($yaml['description'] ?? null)),
            'prefixFields' => $this->call($contentBlock, 'prefixFields'),
            'prefixType' => $this->stringValue($prefixType),
            'fields' => $this->describeFields($yaml['fields'] ?? []),
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    private function describeFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $described = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $identifier = $this->nullableString($field['identifier'] ?? null) ?? '';
            if ($identifier === '') {
                continue;
            }
            $described[$identifier] = array_filter([
                'type' => $field['type'] ?? null,
                'label' => $this->translate($this->nullableString($field['label'] ?? null)),
                'useExistingField' => $field['useExistingField'] ?? null,
                'required' => $field['required'] ?? null,
                'default' => $field['default'] ?? null,
                'fields' => isset($field['fields']) ? $this->describeFields($field['fields']) : null,
            ], static fn(mixed $value): bool => $value !== null && $value !== []);
        }

        return $described;
    }

    private function translate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $languageService = $GLOBALS['LANG'] ?? null;
        if (str_starts_with($value, 'LLL:') && $languageService instanceof LanguageService) {
            $translated = $languageService->sL($value);
            return $translated !== '' ? $translated : $value;
        }

        return $value;
    }

    private function call(object $target, string $method, mixed ...$arguments): mixed
    {
        $callable = [$target, $method];
        if (!is_callable($callable)) {
            throw new ValidationException([
                sprintf('Content Blocks API method %s::%s() is unavailable.', $target::class, $method),
            ]);
        }

        return $callable(...$arguments);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->stringValue($value);
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string)$value->value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value instanceof \Stringable) {
            return $value->__toString();
        }

        throw new ValidationException([
            sprintf('Content Blocks returned %s where a string was expected.', get_debug_type($value)),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function result(array $payload): CallToolResult
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }
}
