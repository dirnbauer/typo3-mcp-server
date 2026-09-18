<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Exception\AccessDeniedException;
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
 * Base class of every MCP tool the registry hands out.
 *
 * execute() is the template method: it enforces the capability manifest and
 * the #[AdminOnly] / #[DevSiteOnly] attributes, then delegates to doExecute()
 * and turns every exception into a structured error result.
 */
abstract class AbstractTool implements ToolInterface
{
    use ExceptionHandlerTrait;

    /**
     * Tool name derived from the class name ("ReadTableTool" -> "ReadTable").
     */
    public function getName(): string
    {
        return str_replace('Tool', '', (new \ReflectionClass($this))->getShortName());
    }

    /**
     * Marked #[AdminOnly]: only administrators may execute the tool.
     */
    public function isAdminOnly(): bool
    {
        return $this->hasAttribute(AdminOnly::class);
    }

    /**
     * Marked #[DevSiteOnly]: listed and executable only while
     * {@see DevSiteToolService::isAvailable()} reports local mode.
     */
    public function isDevSiteOnly(): bool
    {
        return $this->hasAttribute(DevSiteOnly::class);
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
            if ($this->isAdminOnly()) {
                $this->assertAdminUser();
            }
            if ($this->isDevSiteOnly()) {
                GeneralUtility::makeInstance(DevSiteToolService::class)->assertAvailable();
            }
            $this->initialize();
            return $this->doExecute($params);
        } catch (\Throwable $e) {
            return $this->handleException($e, $this->getName());
        }
    }

    /**
     * @param class-string $attribute
     */
    protected function hasAttribute(string $attribute): bool
    {
        return (new \ReflectionClass($this))->getAttributes($attribute) !== [];
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
        } catch (\Throwable $exception) {
            // Capability enforcement is a security boundary. A broken or
            // unavailable container must not silently turn it off.
            throw new AccessDeniedException(
                'capability manifest service',
                'execute tool',
                $exception,
            );
        }
        $this->assertAllowedByManifest($manifest);
    }

    /**
     * Native tools are looked up by name in `x-mcp.tools` / `external_tools`.
     * Bridged tools that carry their own requirement metadata (abilities)
     * override this hook; the fail-closed service lookup above stays shared.
     */
    protected function assertAllowedByManifest(CapabilityManifestService $manifest): void
    {
        $manifest->assertToolAllowed($this->getName());
    }

    protected function initialize(): void {}

    private function assertAdminUser(): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$backendUser->isAdmin()) {
            throw new ValidationException(['This tool requires admin privileges.']);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    abstract protected function doExecute(array $params): CallToolResult;

    /**
     * JSON text result. Invalid UTF-8 (raw column values DataHandler did not
     * sanitize) is substituted so the response stays valid JSON.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResult(array $data, bool $isError = false): CallToolResult
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return new CallToolResult([new TextContent($encoded === false ? '{}' : $encoded)], $isError);
    }

    /**
     * Create an error result (required by ExceptionHandlerTrait)
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }
}
