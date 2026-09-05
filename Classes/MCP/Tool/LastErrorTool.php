<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\DeveloperLogEntryParser;
use Hn\McpServer\Service\DeveloperLogReader;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/** Return the newest error from TYPO3's bounded file-log tail. */
#[AdminOnly]
#[DevSiteOnly]
final class LastErrorTool extends AbstractTool
{
    public function __construct(
        private readonly DeveloperLogReader $logReader,
        private readonly DeveloperLogEntryParser $logEntryParser,
    ) {}

    public function getSchema(): array
    {
        return [
            'description' => 'Admin-only: return the newest error-level TYPO3 file-log entry with exception details and a short stack trace.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'full' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Return the complete raw log entry and stack trace; potentially large.',
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
        $entry = $this->logReader->readLatestError();
        $payload = $entry === null
            ? ['error' => null, 'hint' => 'No error-level entries found in var/log/typo3_*.log.']
            : ['error' => $this->logEntryParser->parse($entry, ($params['full'] ?? false) === true)];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new CallToolResult([new TextContent($json !== false ? $json : '{}')]);
    }
}
