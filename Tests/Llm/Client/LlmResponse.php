<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

/**
 * Represents an LLM response with tool calls
 *
 * @phpstan-type ToolCall array{name: string, arguments: array<string, mixed>}
 */
class LlmResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $rawResponse Decoded chat-completions JSON
     */
    public function __construct(private readonly string $content, private readonly array $toolCalls, private readonly array $rawResponse) {}

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
     * @return list<ToolCall> Tool calls with 'name' and 'arguments' keys
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Get the raw API response for debugging
     *
     * @return array<string, mixed>
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
     * @return list<ToolCall> Array of matching tool calls
     */
    public function getToolCallsByName(string $toolName): array
    {
        return array_values(array_filter($this->toolCalls, fn($call) => $call['name'] === $toolName));
    }
}
