<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Factory for creating and configuring MCP Server instances.
 *
 * Tool dispatch follows MCP ergonomics guidance (Anthropic mcp-builder skill):
 * https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md
 * — including actionable client errors without JSON-RPC internal failures when possible.
 */
final readonly class McpServerFactory
{
    public function __construct(
        private ToolRegistry $toolRegistry,
    ) {}

    /**
     * Create a fully configured MCP Server instance
     *
     * @param callable|null $debugLogger Optional debug logger function
     */
    public function createServer(?callable $debugLogger = null): Server
    {
        $serverName = $this->getServerName();
        $server = new Server($serverName);

        $this->registerHandlers($server, $debugLogger);

        return $server;
    }

    /**
     * Create InitializationOptions with proper version information
     */
    public function createInitializationOptions(Server $server): InitializationOptions
    {
        $notificationOptions = new NotificationOptions();
        $capabilities = $server->getCapabilities($notificationOptions, []);

        return new InitializationOptions(
            serverName: $this->getServerName(),
            serverVersion: $this->getServerVersion(),
            capabilities: $capabilities,
        );
    }

    /**
     * Get the server name from TYPO3 configuration
     */
    public function getServerName(): string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($configuration)) {
            return 'TYPO3 MCP Server';
        }

        $sysConfig = $configuration['SYS'] ?? null;
        if (!is_array($sysConfig)) {
            return 'TYPO3 MCP Server';
        }

        return is_string($sysConfig['sitename'] ?? null) && $sysConfig['sitename'] !== ''
            ? $sysConfig['sitename']
            : 'TYPO3 MCP Server';
    }

    /**
     * Get the server version string including extension and TYPO3 versions
     */
    public function getServerVersion(): string
    {
        $extVersion = ExtensionManagementUtility::getExtensionVersion('mcp_server');
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class)->getVersion();

        return $extVersion . ' (TYPO3 ' . $typo3Version . ')';
    }

    /**
     * Register MCP handlers on the server
     */
    private function registerHandlers(Server $server, ?callable $debugLogger): void
    {
        $toolRegistry = $this->toolRegistry;
        $debug = $debugLogger ?? static fn($msg) => null;

        // Register tool/list handler
        $server->registerHandler('tools/list', static function () use ($toolRegistry, $debug) {
            $debug('Handling tools/list request');
            $tools = [];

            foreach ($toolRegistry->getTools() as $tool) {
                $schema = $tool->getSchema();
                $rawInputSchema = $schema['inputSchema'] ?? [];
                $schema['inputSchema'] = self::normaliseInputSchema(is_array($rawInputSchema) ? $rawInputSchema : []);

                $tools[] = [
                    'name' => $tool->getName(),
                    ...$schema,
                ];
            }

            return ['tools' => $tools];
        });

        // Register tool/call handler
        $server->registerHandler('tools/call', static function ($params) use ($toolRegistry, $debug) {
            $toolName = $params->name;
            $arguments = $params->arguments;

            $debug('Handling tools/call request for tool: ' . $toolName);

            $tool = $toolRegistry->getTool($toolName);
            if (!$tool) {
                // Return CallToolResult instead of throwing: Server::handleMessage maps generic
                // exceptions to JSON-RPC -32603 and exposes the raw message. Tool-level isError
                // matches MCP/mcp-builder expectations for recoverable agent mistakes.
                $debug('Unknown tool name: ' . $toolName);
                $safeName = is_string($toolName) ? $toolName : '';
                $hint = 'Call tools/list for the exact tool names exposed by this server '
                    . '(see TYPO3 docs: Documentation/Tools/Index.rst). '
                    . 'Optional: use a consistent prefix pattern if you rename tools for LLM ergonomics '
                    . '(mcp-builder skill: https://github.com/anthropics/skills/blob/main/skills/mcp-builder/SKILL.md).';

                return new CallToolResult(
                    [new TextContent(
                        $safeName !== ''
                            ? 'Unknown tool "' . $safeName . '". ' . $hint
                            : 'Unknown tool (missing name). ' . $hint,
                    )],
                    true,
                );
            }

            // Exceptions are normalized to CallToolResult by AbstractTool::executeInternal().
            return $tool->execute($arguments);
        });
    }

    /**
     * Normalise an inputSchema so that it survives strict MCP client validation.
     *
     * - Ensures `properties` is always a JSON object (not array), because
     *   `'properties' => []` in PHP serialises to `[]` instead of `{}` and
     *   clients such as Cursor reject the entire tool catalog on that mismatch.
     * - Drops `required` when it is an empty array (invalid per JSON Schema spec).
     *
     * All other JSON Schema keywords (enum, default, minimum, maximum, oneOf,
     * items, etc.) are preserved so that clients can use them for validation
     * and LLMs benefit from the richer parameter descriptions.
     *
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    private static function normaliseInputSchema(array $inputSchema): array
    {
        // Ensure properties is always a JSON object, never a JSON array.
        if (isset($inputSchema['properties'])) {
            $props = $inputSchema['properties'];
            if ($props instanceof \stdClass) {
                // Already correct (empty object)
            } elseif (is_array($props) && $props === []) {
                // Empty PHP array [] would serialise as JSON array []; coerce to object {}.
                $inputSchema['properties'] = new \stdClass();
            }
            // Non-empty associative arrays serialise as JSON objects automatically.
        }

        // Remove empty required arrays — invalid per JSON Schema spec.
        if (isset($inputSchema['required']) && $inputSchema['required'] === []) {
            unset($inputSchema['required']);
        }

        return $inputSchema;
    }
}
