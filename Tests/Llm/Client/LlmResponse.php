<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

/**
 * Represents an LLM response with tool calls
 */
class LlmResponse
{
    public function __construct(private readonly string $content, private readonly array $toolCalls, private readonly array $rawResponse)
    {
    }

    /**
     * Get the text content of the response
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get tool calls made by the LLM
     * 
     * @return array Array of tool calls with 'name' and 'arguments' keys
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Get the raw API response for debugging
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Check if any tool calls were made
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * Get tool calls by name
     * 
     * @param string $toolName
     * @return array Array of matching tool calls
     */
    public function getToolCallsByName(string $toolName): array
    {
        return array_values(array_filter($this->toolCalls, fn($call) => $call['name'] === $toolName));
    }
}