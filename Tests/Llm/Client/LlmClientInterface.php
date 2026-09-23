<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

/**
 * Interface for LLM clients
 * Allows easy switching between providers
 *
 * @phpstan-type LlmTool array{type: string, function: array{name: string, description?: string, parameters?: array<string, mixed>}}
 * @phpstan-type ToolResult array{content: string, error?: string, isError?: bool}
 */
interface LlmClientInterface
{
    /**
     * Complete a prompt with available tools
     *
     * @param string $prompt The user prompt
     * @param list<LlmTool> $tools Available tools in OpenAI function format
     * @param array<string, mixed> $options Additional options (model, temperature, max_tokens, reasoning, cache_control)
     * @return LlmResponse
     */
    public function complete(string $prompt, array $tools, array $options = []): LlmResponse;

    /**
     * Continue a conversation with tool results
     *
     * @param string $initialPrompt The original user prompt
     * @param LlmResponse $previousResponse The previous LLM response containing tool calls
     * @param list<ToolResult> $toolResults Tool execution results, in the order of the previous response's tool calls
     * @param list<LlmTool> $tools Available tools in OpenAI function format
     * @param array<string, mixed> $options Additional options
     * @return LlmResponse
     */
    public function completeWithHistory(
        string $initialPrompt,
        LlmResponse $previousResponse,
        array $toolResults,
        array $tools,
        array $options = [],
    ): LlmResponse;
}
