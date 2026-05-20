<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\LocallangFileService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Create or extend XLF translation files inside a TYPO3 extension.
 */
#[AdminOnly]
#[DevSiteOnly]
final class CreateLocallangTool extends AbstractTool
{
    public function __construct(
        private readonly LocallangFileService $locallangFileService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Create or extend an XLF language file in a TYPO3 extension. '
                . 'Each translation unit needs id and source; target is optional. '
                . 'Dev-site only (DDEV / localUnsafeMode). Requires admin privileges.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'extensionKey' => [
                        'type' => 'string',
                        'description' => 'TYPO3 extension key, e.g. "my_extension".',
                    ],
                    'fileName' => [
                        'type' => 'string',
                        'description' => 'Language file name, e.g. "locallang.xlf" or "locallang_db.xlf".',
                    ],
                    'transUnits' => [
                        'type' => 'array',
                        'description' => 'Translation units with id, source, and optional target.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'source' => ['type' => 'string'],
                                'target' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'source'],
                        ],
                    ],
                    'extensionBasePath' => [
                        'type' => 'string',
                        'description' => 'Base path for non-loaded extensions. Defaults to "packages".',
                        'default' => 'packages',
                    ],
                ],
                'required' => ['extensionKey', 'fileName', 'transUnits'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $extensionKey = is_string($params['extensionKey'] ?? null) ? trim($params['extensionKey']) : '';
        $fileName = is_string($params['fileName'] ?? null) ? trim($params['fileName']) : '';
        $extensionBasePath = is_string($params['extensionBasePath'] ?? null) && $params['extensionBasePath'] !== ''
            ? trim($params['extensionBasePath'])
            : 'packages';

        if ($extensionKey === '' || $fileName === '') {
            throw new ValidationException(['extensionKey and fileName are required.']);
        }

        if (!isset($params['transUnits']) || !is_array($params['transUnits'])) {
            throw new ValidationException(['transUnits array is required.']);
        }

        /** @var list<array{id: string, source: string, target?: string}> $transUnits */
        $transUnits = [];
        foreach ($params['transUnits'] as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            if (!is_string($unit['id'] ?? null) || !is_string($unit['source'] ?? null)) {
                throw new ValidationException(['Each transUnits entry requires string id and source.']);
            }
            $entry = [
                'id' => $unit['id'],
                'source' => $unit['source'],
            ];
            if (isset($unit['target']) && is_string($unit['target'])) {
                $entry['target'] = $unit['target'];
            }
            $transUnits[] = $entry;
        }

        $summary = $this->locallangFileService->createOrExtend(
            $extensionKey,
            $fileName,
            $transUnits,
            $extensionBasePath,
        );

        $json = json_encode([
            'status' => 'ok',
            'extensionKey' => $extensionKey,
            'fileName' => $fileName,
            'summary' => $summary,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }

        return new CallToolResult([new TextContent($json)]);
    }
}
