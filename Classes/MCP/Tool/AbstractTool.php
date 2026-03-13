<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Traits\ExceptionHandlerTrait;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

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
    
    public function execute(array $params): CallToolResult
    {
        return $this->executeInternal($params);
    }

    /**
     * Internal execution with consistent error handling.
     * Subclasses that override execute() call this to preserve the template method.
     */
    protected function executeInternal(array $params): CallToolResult
    {
        try {
            $this->initialize();
            return $this->doExecute($params);
        } catch (\Throwable $e) {
            return $this->handleException($e, $this->getName());
        }
    }

    protected function initialize(): void
    {
    }

    abstract protected function doExecute(array $params): CallToolResult;
    
    /**
     * Create an error result (required by ExceptionHandlerTrait)
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }
}
