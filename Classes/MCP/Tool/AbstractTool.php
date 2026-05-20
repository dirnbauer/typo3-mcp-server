<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Attribute\AdminOnly;
use Hn\McpServer\MCP\Tool\Attribute\DevSiteOnly;
use Hn\McpServer\Service\CapabilityManifestService;
use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Traits\ExceptionHandlerTrait;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for MCP tools
 *
 * Implements the Template Method pattern for consistent error handling
 * across all tools. The execute() method is final and handles all
 * exceptions, while subclasses implement doExecute() for their logic.
 */
abstract class AbstractTool implements ToolInterface
{
    use ExceptionHandlerTrait;

    /**
     * Get the tool name based on the class name
     */
    public function getName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return str_replace('Tool', '', $className);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(array $params): CallToolResult
    {
        return $this->executeInternal($params);
    }

    /**
     * Internal execution with consistent error handling.
     * Subclasses that override execute() call this to preserve the template method.
     *
     * @param array<string, mixed> $params
     */
    protected function executeInternal(array $params): CallToolResult
    {
        try {
            $this->enforceCapabilityManifest();
            $this->enforceAdminOnly();
            $this->enforceDevSiteOnly();
            $this->initialize();
            return $this->doExecute($params);
        } catch (\Throwable $e) {
            return $this->handleException($e, $this->getName());
        }
    }

    /**
     * Refuse to execute when Configuration/Capabilities.yaml has not declared
     * this tool's required subsystems. Disabling the manifest setting bypasses
     * the check (see CapabilityManifestService::isEnforced()).
     */
    private function enforceCapabilityManifest(): void
    {
        try {
            $manifest = GeneralUtility::makeInstance(CapabilityManifestService::class);
        } catch (\Throwable) {
            // DI not booted (e.g. very early CLI); skip — the runtime
            // execution path will eventually hit the manifest in normal calls.
            return;
        }
        $manifest->assertToolAllowed($this->getName());
    }

    protected function initialize(): void {}

    /**
     * Enforce the #[AdminOnly] attribute if present on the concrete tool class.
     */
    private function enforceAdminOnly(): void
    {
        $reflection = new \ReflectionClass($this);
        if ($reflection->getAttributes(AdminOnly::class) === []) {
            return;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            throw new ValidationException(['This tool requires admin privileges.']);
        }
    }

    private function enforceDevSiteOnly(): void
    {
        $reflection = new \ReflectionClass($this);
        if ($reflection->getAttributes(DevSiteOnly::class) === []) {
            return;
        }

        try {
            $devSiteTools = GeneralUtility::makeInstance(DevSiteToolService::class);
        } catch (\Throwable) {
            return;
        }

        $devSiteTools->assertAvailable();
    }

    /**
     * @param array<string, mixed> $params
     */
    abstract protected function doExecute(array $params): CallToolResult;

    /**
     * Create an error result (required by ExceptionHandlerTrait)
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }
}
