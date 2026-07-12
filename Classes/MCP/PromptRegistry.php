<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Mcp\Server\McpServerException;
use Mcp\Types\CacheableResult;
use Mcp\Types\GetPromptResult;
use Mcp\Types\ListPromptsResult;
use Mcp\Types\Prompt;
use Mcp\Types\PromptArgument;
use Mcp\Types\PromptMessage;
use Mcp\Types\Role;
use Mcp\Types\TextContent;

/**
 * Standard MCP Prompts projection for user-invocable bundled skills.
 */
final readonly class PromptRegistry
{
    public function __construct(
        private SkillRegistry $skillRegistry,
    ) {}

    public function listPrompts(): ListPromptsResult
    {
        $prompts = [];
        foreach ($this->skillRegistry->getSkills() as $skill) {
            if (!$skill->userInvocable) {
                continue;
            }
            $prompts[] = new Prompt(
                name: $skill->name,
                description: $skill->description,
                arguments: [
                    new PromptArgument(
                        name: 'request',
                        description: 'What the user wants this workflow to accomplish.',
                        required: false,
                    ),
                    new PromptArgument(
                        name: 'context',
                        description: 'Optional page, record, language, or editorial context.',
                        required: false,
                    ),
                ],
                title: $this->titleFromName($skill->name),
            );
        }

        $result = new ListPromptsResult($prompts);
        $result->setCacheHints(60_000, CacheableResult::CACHE_SCOPE_PRIVATE);
        return $result;
    }

    /**
     * @param array<string, string> $arguments
     */
    public function getPrompt(string $name, array $arguments = []): GetPromptResult
    {
        $skill = $this->skillRegistry->getSkill($name);
        if ($skill === null || !$skill->userInvocable) {
            throw McpServerException::unknownPrompt($name);
        }

        $request = trim($arguments['request'] ?? 'Follow this workflow for the current user request.');
        $context = trim($arguments['context'] ?? '');
        $message = $skill->markdown
            . "\n\n## Current request\n\n"
            . ($request !== '' ? $request : 'Follow this workflow for the current user request.');
        if ($context !== '') {
            $message .= "\n\n## Additional context\n\n" . $context;
        }

        return new GetPromptResult(
            messages: [new PromptMessage(Role::USER, new TextContent($message))],
            description: $skill->description,
        );
    }

    private function titleFromName(string $name): string
    {
        return ucwords(str_replace('-', ' ', $name));
    }
}
