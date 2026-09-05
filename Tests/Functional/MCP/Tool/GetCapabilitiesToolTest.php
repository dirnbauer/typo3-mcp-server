<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetCapabilitiesTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class GetCapabilitiesToolTest extends AbstractFunctionalTest
{
    #[Test]
    public function returnsTheShippedManifestAndRuntimeMode(): void
    {
        $tool = $this->get(GetCapabilitiesTool::class);

        $result = $tool->execute([]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        // Manifest must round-trip the shipped Configuration/Capabilities.yaml.
        self::assertArrayHasKey('manifest', $payload);
        self::assertSame('mcp_server', $payload['manifest']['extension'] ?? null);
        self::assertContains('database:read', $payload['manifest']['subsystems'] ?? []);
        self::assertContains('file:write', $payload['manifest']['subsystems'] ?? []);

        // Fine-grained MCP inventory is namespaced away from the public TYPO3
        // capability schema, while remaining fully introspectable by clients.
        $mcp = $payload['manifest']['x-mcp'] ?? null;
        self::assertIsArray($mcp);
        self::assertArrayHasKey('ReadTable', $mcp['tools'] ?? []);
        self::assertSame(['database:read'], $mcp['tools']['ReadTable']);
        self::assertSame(
            ['cli:safe', 'database:read', 'database:write', 'scheduler:task', 'network:scheduler'],
            $mcp['tools']['SolrIndexQueue'],
        );
        self::assertSame('ReadTable', $mcp['commands']['mcp:read-table']['tool'] ?? null);
        self::assertArrayHasKey('typo3-content-edit', $mcp['skills'] ?? []);

        // Network outbound default is closed (`self`) and uses the structured
        // capability-manifest representation.
        self::assertSame('self', $payload['manifest']['network']['outbound'][0]['host'] ?? null);

        // Runtime mode is reported.
        self::assertArrayHasKey('localMode', $payload);
        self::assertArrayHasKey('enabled', $payload['localMode']);
        self::assertArrayHasKey('enforced', $payload);
        self::assertTrue($payload['enforced']);
        self::assertSame(1, $payload['user']['uid']);
        self::assertTrue($payload['user']['isAdmin']);
        self::assertSame('all', $payload['user']['pageAccess']['scope']);
        self::assertSame([], $payload['user']['pageAccess']['mountPageIds']);
        self::assertTrue($payload['user']['tablePermissions']['tt_content']['write']);
        self::assertArrayNotHasKey('password', $payload['user']);
        self::assertArrayNotHasKey('email', $payload['user']);
    }

    public function testRestrictedEditorSummaryUsesPageAndTableGuardsWithoutSelectingAWorkspace(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], []);
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $user->user['admin'] = 0;
        $user->user['db_mountpoints'] = '2';
        $user->groupData['webmounts'] = '2';
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = '';
        $user->userGroupsUID = [1];
        $workspaceBefore = $user->workspace;
        $workspaceCountBefore = $this->connectionPool->getConnectionForTable('sys_workspace')->count('*', 'sys_workspace', []);

        $result = $this->get(GetCapabilitiesTool::class)->execute([]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        $summary = $payload['user'];
        self::assertFalse($summary['isAdmin']);
        self::assertSame(['scope' => 'mounted', 'mountPageIds' => [2]], $summary['pageAccess']);
        self::assertSame(['read' => true, 'write' => false], $summary['tablePermissions']['tt_content']);
        self::assertSame(['read' => false, 'write' => false], $summary['tablePermissions']['sys_file_reference']);
        self::assertSame($workspaceBefore, $summary['workspaceId']);
        self::assertSame($workspaceBefore, $user->workspace);
        self::assertSame($workspaceCountBefore, $this->connectionPool->getConnectionForTable('sys_workspace')->count('*', 'sys_workspace', []));
    }

    public function testEditorWithNoMountsIsNotDescribedAsHavingFullTreeAccess(): void
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $user->user['admin'] = 0;
        $user->user['db_mountpoints'] = '';
        $user->groupData['webmounts'] = '';
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = '';

        $result = $this->get(GetCapabilitiesTool::class)->execute([]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['scope' => 'none', 'mountPageIds' => []], $payload['user']['pageAccess']);
    }

    public function testManifestStillWorksWithoutAnAuthenticatedUserContext(): void
    {
        unset($GLOBALS['BE_USER']);
        $result = $this->get(GetCapabilitiesTool::class)->execute([]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode((string)$result->content[0]->text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['authenticated' => false], $payload['user']);
        self::assertSame('mcp_server', $payload['manifest']['extension']);
    }
}
