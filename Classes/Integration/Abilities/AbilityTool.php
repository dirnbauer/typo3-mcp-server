<?php

declare(strict_types=1);

namespace Hn\McpServer\Integration\Abilities;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\CapabilityManifestService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;
use Webconsulting\Abilities\Projection\Mcp\McpToolDescriptor;

/**
 * One MCP-exposed ability projected as a native tool.
 *
 * Instances are created by AbilityToolBridge from McpProjection descriptors,
 * never by the container. Execution goes through McpProjection, so the
 * governed abilities pipeline (policy gate, schema validation, permission
 * check, execution traces) runs exactly as on every other abilities surface,
 * while AbstractTool adds this extension's capability-manifest gate and
 * error normalization in front of it.
 */
final class AbilityTool extends AbstractTool
{
    public function __construct(
        private readonly McpToolDescriptor $descriptor,
        private readonly McpProjection $projection,
    ) {}

    public function getName(): string
    {
        return $this->descriptor->name;
    }

    public function getDefinition(): AbilityDefinition
    {
        return $this->descriptor->definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return [
            'description' => $this->descriptor->description,
            'inputSchema' => $this->descriptor->inputSchema,
            'annotations' => $this->descriptor->annotations,
        ];
    }

    /**
     * Abilities are gated by their declared side effects instead of a
     * per-name manifest entry; see CapabilityManifestService.
     */
    protected function assertAllowedByManifest(CapabilityManifestService $manifest): void
    {
        $manifest->assertAbilityToolAllowed($this->getName(), $this->descriptor->definition->sideEffects);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function doExecute(array $params): CallToolResult
    {
        $result = $this->projection->execute($this->getName(), $params, $this->createExecutionContext());

        $json = json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            return $this->createErrorResult('Ability result could not be encoded as JSON: ' . json_last_error_msg());
        }

        return new CallToolResult([new TextContent($json)], !$result->ok);
    }

    /**
     * MCP is a trusted abilities surface: the HTTP endpoint or the CLI
     * bootstrap has already authenticated and hydrated the backend user, so
     * scope checks are skipped while the abilities policy and the ability's
     * own permission check still apply. Without an authenticated backend
     * user the tool fails closed.
     */
    private function createExecutionContext(): ExecutionContext
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid = $backendUser instanceof BackendUserAuthentication ? ($backendUser->user['uid'] ?? 0) : 0;
        if (!is_numeric($uid) || (int)$uid <= 0) {
            throw new AccessDeniedException('active TYPO3 backend user', 'execute ' . $this->getName());
        }

        return ExecutionContext::mcp((int)$uid);
    }
}
