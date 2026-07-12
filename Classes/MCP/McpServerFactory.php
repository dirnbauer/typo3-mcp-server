<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\Service\ToolResultNormalizer;
use Hn\McpServer\Service\ToolSchemaOptimizer;
use Mcp\Server\InitializationOptions;
use Mcp\Server\McpServerException;
use Mcp\Server\NotificationOptions;
use Mcp\Server\Server;
use Mcp\Types\CacheableResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\PromptArguments;
use Mcp\Types\Tool;
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
        private ?ResourceRegistry $resourceRegistry = null,
        private ?ToolSchemaOptimizer $schemaOptimizer = null,
        private ?PromptRegistry $promptRegistry = null,
        private ?ToolResultNormalizer $resultNormalizer = null,
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
        $schemaOptimizer = $this->resolveSchemaOptimizer();
        $debug = $debugLogger ?? static fn($msg) => null;

        // Register tool/list handler
        $server->registerHandler('tools/list', static function () use ($toolRegistry, $schemaOptimizer, $debug) {
            $debug('Handling tools/list request');
            $tools = [];

            $registeredTools = $toolRegistry->getTools();
            ksort($registeredTools, SORT_STRING);
            foreach ($registeredTools as $tool) {
                $schema = $tool->getSchema();

                // Condense verbose descriptions to save context-window tokens
                // (default; reversible via the schemaDetail setting). Full text
                // stays available through the GetCapabilities tool.
                if ($schemaOptimizer !== null) {
                    try {
                        $schema = $schemaOptimizer->optimize($schema);
                    } catch (\Throwable) {
                        // Never let optimisation break the catalog — fall back to raw.
                    }
                }

                $rawInputSchema = $schema['inputSchema'] ?? [];
                $schema['inputSchema'] = self::normaliseInputSchema(is_array($rawInputSchema) ? $rawInputSchema : []);

                $tools[] = Tool::fromArray([
                    'name' => $tool->getName(),
                    ...$schema,
                ]);
            }

            $result = new ListToolsResult($tools);
            $result->setCacheHints(30_000, CacheableResult::CACHE_SCOPE_PRIVATE);
            return $result;
        });

        // Register tool/call handler
        $resultNormalizer = $this->resultNormalizer ?? new ToolResultNormalizer();
        $server->registerHandler('tools/call', static function ($params) use ($toolRegistry, $resultNormalizer, $debug) {
            $toolName = $params->name;
            // MCP allows clients to omit `arguments` for parameterless tools.
            // The SDK models that field as ?array while ToolInterface accepts
            // an array, so normalize the valid null representation here.
            $arguments = $params->arguments ?? [];

            $debug('Handling tools/call request for tool: ' . $toolName);

            $tool = $toolRegistry->getTool($toolName);
            if (!$tool) {
                $debug('Unknown tool name: ' . $toolName);
                $safeName = is_string($toolName) ? $toolName : '';
                throw McpServerException::unknownTool($safeName !== '' ? $safeName : '(missing name)');
            }

            // Exceptions are normalized to CallToolResult by AbstractTool::executeInternal().
            return $resultNormalizer->normalize($tool->execute($arguments));
        });

        $this->registerResourceHandlers($server, $debug);
        $this->registerPromptHandlers($server, $debug);
    }

    /**
     * Use the injected optimiser when available; otherwise build one lazily so
     * the optimisation also applies on code paths that construct the factory
     * directly. Returns null only when no instance can be created at all.
     */
    private function resolveSchemaOptimizer(): ?ToolSchemaOptimizer
    {
        if ($this->schemaOptimizer !== null) {
            return $this->schemaOptimizer;
        }
        try {
            return GeneralUtility::makeInstance(ToolSchemaOptimizer::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function registerResourceHandlers(Server $server, callable $debug): void
    {
        if ($this->resourceRegistry === null) {
            return;
        }

        $resourceRegistry = $this->resourceRegistry;

        $server->registerHandler('resources/list', static function () use ($resourceRegistry, $debug) {
            $debug('Handling resources/list request');
            if (!$resourceRegistry->isAvailable()) {
                return new ListResourcesResult([]);
            }

            $result = $resourceRegistry->listResources();
            $result->setCacheHints(60_000, CacheableResult::CACHE_SCOPE_PRIVATE);
            return $result;
        });

        $server->registerHandler('resources/templates/list', static function () use ($resourceRegistry, $debug) {
            $debug('Handling resources/templates/list request');
            if (!$resourceRegistry->isAvailable()) {
                return new ListResourceTemplatesResult([]);
            }

            $result = $resourceRegistry->listResourceTemplates();
            $result->setCacheHints(60_000, CacheableResult::CACHE_SCOPE_PRIVATE);
            return $result;
        });

        $server->registerHandler('resources/read', static function ($params) use ($resourceRegistry, $debug, $server) {
            $uri = '';
            if (is_object($params) && property_exists($params, 'uri') && is_string($params->uri)) {
                $uri = $params->uri;
            }
            $debug('Handling resources/read request for URI: ' . $uri);

            try {
                return $resourceRegistry->readResource($uri);
            } catch (\InvalidArgumentException) {
                $modernErrorCode = $server->clientSupportsFeature('resource_not_found_invalid_params');
                throw McpServerException::unknownResource($uri, $modernErrorCode);
            }
        });
    }

    private function registerPromptHandlers(Server $server, callable $debug): void
    {
        if ($this->promptRegistry === null) {
            return;
        }

        $promptRegistry = $this->promptRegistry;
        $server->registerHandler('prompts/list', static function () use ($promptRegistry, $debug) {
            $debug('Handling prompts/list request');
            return $promptRegistry->listPrompts();
        });
        $server->registerHandler('prompts/get', static function ($params) use ($promptRegistry, $debug) {
            $name = is_object($params) && property_exists($params, 'name') && is_string($params->name)
                ? $params->name
                : '';
            $arguments = [];
            if (is_object($params) && property_exists($params, 'arguments') && $params->arguments instanceof PromptArguments) {
                $serialized = $params->arguments->jsonSerialize();
                if (is_array($serialized)) {
                    foreach ($serialized as $key => $value) {
                        if (is_string($key) && is_string($value)) {
                            $arguments[$key] = $value;
                        }
                    }
                }
            }
            $debug('Handling prompts/get request for prompt: ' . $name);
            return $promptRegistry->getPrompt($name, $arguments);
        });
    }

    /**
     * Normalise an inputSchema so that it survives strict MCP client validation.
     *
     * - Ensures `properties` is an array suitable for the SDK's typed
     *   ToolInputProperties model. That model serialises an empty map as `{}`
     *   so strict clients never receive the invalid JSON array `[]`.
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
        if (($inputSchema['properties'] ?? null) instanceof \stdClass) {
            $inputSchema['properties'] = [];
        }

        // Remove empty required arrays — invalid per JSON Schema spec.
        if (isset($inputSchema['required']) && $inputSchema['required'] === []) {
            unset($inputSchema['required']);
        }

        return $inputSchema;
    }
}
