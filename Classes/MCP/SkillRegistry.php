<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Discovers the bundled Agent Skills and validates their front matter.
 *
 * Skills are not a core MCP primitive. The registry projects them onto MCP
 * resources (their full Markdown) and prompts (user-invoked workflows).
 */
final class SkillRegistry
{
    /** @var array<string, SkillDefinition>|null */
    private ?array $skills = null;

    public function __construct(
        private readonly ?string $skillsPath = null,
    ) {}

    /**
     * @return array<string, SkillDefinition>
     */
    public function getSkills(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $skills = [];
        $path = $this->skillsPath
            ?? ExtensionManagementUtility::extPath('mcp_server') . 'Resources/Private/Skills';
        $matchedFiles = glob(rtrim($path, '/') . '/*/SKILL.md');
        $files = $matchedFiles !== false ? $matchedFiles : [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $skill = $this->loadSkill($file);
            if (isset($skills[$skill->name])) {
                throw new \RuntimeException('Duplicate bundled skill name: ' . $skill->name);
            }
            $skills[$skill->name] = $skill;
        }

        ksort($skills, SORT_STRING);
        return $this->skills = $skills;
    }

    public function getSkill(string $name): ?SkillDefinition
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            return null;
        }

        return $this->getSkills()[$name] ?? null;
    }

    private function loadSkill(string $file): SkillDefinition
    {
        $markdown = file_get_contents($file);
        if (!is_string($markdown)) {
            throw new \RuntimeException('Unable to read bundled skill: ' . $file);
        }
        if (preg_match('/\A---\R(.*?)\R---\R/s', $markdown, $match) !== 1) {
            throw new \RuntimeException('Bundled skill has no YAML front matter: ' . $file);
        }

        $metadata = Yaml::parse($match[1]);
        if (!is_array($metadata)) {
            throw new \RuntimeException('Bundled skill front matter is invalid: ' . $file);
        }

        $name = $metadata['name'] ?? null;
        $description = $metadata['description'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1) {
            throw new \RuntimeException('Bundled skill name is invalid: ' . $file);
        }
        if ($name !== basename(dirname($file))) {
            throw new \RuntimeException('Bundled skill name must match its directory: ' . $file);
        }
        if (!is_string($description) || trim($description) === '') {
            throw new \RuntimeException('Bundled skill description is missing: ' . $file);
        }

        return new SkillDefinition(
            name: $name,
            description: trim($description),
            userInvocable: filter_var($metadata['user-invocable'] ?? false, FILTER_VALIDATE_BOOL),
            markdown: $markdown,
        );
    }
}
