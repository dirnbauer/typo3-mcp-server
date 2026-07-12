<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

/**
 * Immutable metadata and source for one bundled Agent Skill.
 */
final readonly class SkillDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $userInvocable,
        public string $markdown,
    ) {}
}
