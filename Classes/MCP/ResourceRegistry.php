<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\Service\DevSiteToolService;
use Hn\McpServer\Service\TcaResourceFormatter;
use Mcp\Types\CacheableResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;

/**
 * MCP resources exposed by the extension.
 *
 * Bundled skills are safe, static resources and are always available. TCA
 * introspection remains restricted to DDEV / local development mode.
 */
final readonly class ResourceRegistry
{
    public const URI_OVERVIEW = 'typo3-mcp:///tca';
    public const URI_TABLE_PREFIX = 'typo3-mcp:///tca/';
    public const LEGACY_URI_OVERVIEW = 'typo3-mcp://tca';
    public const LEGACY_URI_TABLE_PREFIX = 'typo3-mcp://tca/';
    public const URI_SKILLS_OVERVIEW = 'typo3-mcp:///skills';
    public const URI_SKILL_PREFIX = 'typo3-mcp:///skills/';

    public function __construct(
        private TcaResourceFormatter $tcaResourceFormatter,
        private DevSiteToolService $devSiteToolService,
        private SkillRegistry $skillRegistry,
    ) {}

    public function isAvailable(): bool
    {
        return $this->skillRegistry->getSkills() !== [] || $this->isTcaAvailable();
    }

    public function isTcaAvailable(): bool
    {
        return $this->devSiteToolService->isAvailable();
    }

    public function listResources(): ListResourcesResult
    {
        $resources = [
            new Resource(
                name: 'typo3_skills_overview',
                uri: self::URI_SKILLS_OVERVIEW,
                description: 'Bundled editor-safe TYPO3 workflows exposed as Agent Skills and MCP prompts.',
                mimeType: 'text/markdown',
            ),
        ];
        foreach ($this->skillRegistry->getSkills() as $skill) {
            $resources[] = new Resource(
                name: 'typo3_skill_' . str_replace('-', '_', $skill->name),
                uri: self::URI_SKILL_PREFIX . $skill->name,
                description: $skill->description,
                mimeType: 'text/markdown',
            );
        }
        if ($this->isTcaAvailable()) {
            $resources[]
            = new Resource(
                name: 'typo3_tca_overview',
                uri: self::URI_OVERVIEW,
                description: 'Overview of TYPO3 database tables accessible to the current backend user.',
                mimeType: 'text/markdown',
            );
        }

        return new ListResourcesResult($resources);
    }

    public function listResourceTemplates(): ListResourceTemplatesResult
    {
        $templates = [
            new ResourceTemplate(
                name: 'typo3_skill',
                uriTemplate: self::URI_SKILL_PREFIX . '{skillName}',
                description: 'One bundled editor-safe TYPO3 workflow in Agent Skills Markdown format.',
                mimeType: 'text/markdown',
            ),
        ];
        if ($this->isTcaAvailable()) {
            $templates[]
            = new ResourceTemplate(
                name: 'typo3_tca_table',
                uriTemplate: self::URI_TABLE_PREFIX . '{tableName}',
                description: 'Detailed TCA configuration for one accessible table.',
                mimeType: 'text/markdown',
            );
        }

        return new ListResourceTemplatesResult($templates);
    }

    public function readResource(string $uri): ReadResourceResult
    {
        if ($uri === self::URI_SKILLS_OVERVIEW) {
            return $this->withPrivateCache(new ReadResourceResult([
                new SpecTextResourceContents(
                    uri: $uri,
                    text: $this->renderSkillsOverview(),
                    mimeType: 'text/markdown',
                ),
            ]));
        }
        if (str_starts_with($uri, self::URI_SKILL_PREFIX)) {
            $name = substr($uri, strlen(self::URI_SKILL_PREFIX));
            $skill = $this->skillRegistry->getSkill($name);
            if ($skill === null) {
                throw new \InvalidArgumentException('Unknown skill resource URI: ' . $uri);
            }

            return $this->withPrivateCache(new ReadResourceResult([
                new SpecTextResourceContents(
                    uri: $uri,
                    text: $skill->markdown,
                    mimeType: 'text/markdown',
                ),
            ]));
        }

        $this->devSiteToolService->assertAvailable();

        if ($uri === self::URI_OVERVIEW || $uri === self::LEGACY_URI_OVERVIEW) {
            return $this->withPrivateCache(new ReadResourceResult([
                new SpecTextResourceContents(
                    uri: $uri,
                    text: $this->tcaResourceFormatter->renderOverview(),
                    mimeType: 'text/markdown',
                ),
            ]));
        }

        $tableName = null;
        if (str_starts_with($uri, self::URI_TABLE_PREFIX)) {
            $tableName = substr($uri, strlen(self::URI_TABLE_PREFIX));
        } elseif (str_starts_with($uri, self::LEGACY_URI_TABLE_PREFIX)) {
            $tableName = substr($uri, strlen(self::LEGACY_URI_TABLE_PREFIX));
        }

        if ($tableName !== null) {
            if ($tableName === '' || preg_match('/^[a-z0-9_]+$/', $tableName) !== 1) {
                throw new \InvalidArgumentException('Invalid TCA resource URI: ' . $uri);
            }

            return $this->withPrivateCache(new ReadResourceResult([
                new SpecTextResourceContents(
                    uri: $uri,
                    text: $this->tcaResourceFormatter->renderTable($tableName),
                    mimeType: 'text/markdown',
                ),
            ]));
        }

        throw new \InvalidArgumentException('Unknown resource URI: ' . $uri);
    }

    private function withPrivateCache(ReadResourceResult $result): ReadResourceResult
    {
        $result->setCacheHints(60_000, CacheableResult::CACHE_SCOPE_PRIVATE);
        return $result;
    }

    private function renderSkillsOverview(): string
    {
        $lines = [
            '# TYPO3 MCP Skills',
            '',
            'These bundled workflows are also exposed through MCP `prompts/list` and `prompts/get`,',
            'which is how interoperable MCP clients present user-invoked slash commands.',
            '',
        ];
        foreach ($this->skillRegistry->getSkills() as $skill) {
            $lines[] = sprintf(
                '- [%s](%s%s) — %s',
                $skill->name,
                self::URI_SKILL_PREFIX,
                $skill->name,
                $skill->description,
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
