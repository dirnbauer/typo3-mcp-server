<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractRecordTool extends AbstractTool
{
    /**
     * Workspace ID requested by the current tool call (null = auto-select).
     */
    private ?int $requestedWorkspaceId = null;

    public function __construct(
        protected readonly TableAccessService $tableAccessService,
        protected readonly WorkspaceContextService $workspaceContextService,
    ) {}

    /**
     * Override execute to extract workspace_id before initialize() runs.
     *
     * @param array<string, mixed> $params
     */
    final public function execute(array $params): CallToolResult
    {
        if (isset($params['workspace_id']) && is_numeric($params['workspace_id'])) {
            $this->requestedWorkspaceId = (int)$params['workspace_id'];
            unset($params['workspace_id']);
        } else {
            $this->requestedWorkspaceId = null;
        }
        return parent::executeInternal($params);
    }

    /**
     * Wraps the concrete schema with the optional workspace_id property.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        $schema = $this->getToolSchema();
        $inputSchema = isset($schema['inputSchema']) && is_array($schema['inputSchema']) ? $schema['inputSchema'] : [];

        if (isset($inputSchema['properties']) && $inputSchema['properties'] instanceof \stdClass) {
            $props = (array)$inputSchema['properties'];
        } elseif (isset($inputSchema['properties']) && is_array($inputSchema['properties'])) {
            $props = $inputSchema['properties'];
        } else {
            $props = [];
        }

        $props['workspace_id'] = [
            'type' => 'integer',
            'description' => 'Optional workspace ID for this call. Changes are staged in that workspace (not live). '
                . 'Use the ListWorkspaces tool to list IDs. Omit to use the server-selected draft workspace.',
        ];
        $inputSchema['properties'] = $props;
        $schema['inputSchema'] = $inputSchema;

        return $schema;
    }

    /**
     * Concrete tools implement this instead of getSchema().
     *
     * @return array<string, mixed>
     */
    abstract protected function getToolSchema(): array;

    protected function initialize(): void
    {
        parent::initialize();

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return;
        }

        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($backendUser);

        if ($this->requestedWorkspaceId !== null) {
            $this->workspaceContextService->switchToWorkspace($backendUser, $this->requestedWorkspaceId);
        } else {
            $this->workspaceContextService->switchToOptimalWorkspace($backendUser);
        }
    }

    /**
     * Ensure a table can be accessed for the given operation
     *
     * @param string $table Table name
     * @param string $operation Operation type (read, write, delete)
     * @throws \InvalidArgumentException If access is denied
     */
    protected function ensureTableAccess(string $table, string $operation = 'read'): void
    {
        $this->tableAccessService->validateTableAccess($table, $operation);
    }
    /**
     * Create a successful result with text content
     */
    protected function createSuccessResult(string $content): CallToolResult
    {
        return new CallToolResult([new TextContent($content)]);
    }

    /**
     * Create a successful result with JSON content.
     *
     * Workspace overlays can surface raw column values that DataHandler chose
     * not to sanitize (binary garbage smuggled into a string field, broken
     * UTF-8 sequences, etc.). Substitute invalid bytes so the response remains
     * valid JSON instead of failing with a TextContent type error.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResult(array $data): CallToolResult
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            $encoded = '{}';
        }
        return new CallToolResult([new TextContent($encoded)]);
    }

    /**
     * Create an error result
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }

    /**
     * Check if a table exists and is accessible
     */
    protected function tableExists(string $table): bool
    {
        return $this->tableAccessService->canAccessTable($table);
    }

    /**
     * Get extension key from table name
     */
    protected function getExtensionFromTable(string $table): string
    {
        // Core tables
        if (in_array($table, ['pages', 'tt_content', 'sys_category', 'sys_file', 'sys_file_reference', 'sys_file_metadata'])) {
            return 'core';
        }

        // Extension tables usually have a prefix like tx_news_domain_model_news
        if (str_starts_with($table, 'tx_')) {
            $parts = explode('_', $table);
            if (count($parts) >= 2) {
                return $parts[1]; // Return the extension name
            }
        }

        return 'unknown';
    }

    /**
     * Check if a table is workspace-capable
     */
    protected function isTableWorkspaceCapable(string $table): bool
    {
        $accessInfo = $this->tableAccessService->getTableAccessInfo($table);
        return $accessInfo['workspace_capable'];
    }

    /**
     * Get workspace capability information for a table
     *
     * @return array{workspace_capable: bool, reason: string}
     */
    protected function getWorkspaceCapabilityInfo(string $table): array
    {
        $accessInfo = $this->tableAccessService->getTableAccessInfo($table);

        if (!$accessInfo['accessible']) {
            return [
                'workspace_capable' => false,
                'reason' => implode(', ', $accessInfo['reasons']),
            ];
        }

        return [
            'workspace_capable' => $accessInfo['workspace_capable'],
            'reason' => $accessInfo['workspace_capable']
                ? 'Table supports workspace operations'
                : 'Table is not workspace-capable',
        ];
    }

    /**
     * Get a human-readable label for a table
     */
    protected function getTableLabel(string $table): string
    {
        if (!$this->tableExists($table)) {
            return $table;
        }

        return TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));
    }

    /**
     * Check if a table is hidden (not accessible through TableAccessService)
     */
    protected function isTableHidden(string $table): bool
    {
        // Use TableAccessService to determine if table is accessible
        // If it's not accessible, it's effectively "hidden" from MCP
        return !$this->tableAccessService->canAccessTable($table);
    }

    /**
     * Get workspace hint text to prepend to tool output.
     * Returns empty string when in live workspace.
     */
    protected function getWorkspaceHint(): string
    {
        $info = $this->workspaceContextService->getWorkspaceInfo();
        if ($info['is_live']) {
            return '';
        }
        return '[WORKSPACE: "' . $info['title'] . '" — Edits are staged as drafts, not yet live.]' . "\n\n";
    }
}
