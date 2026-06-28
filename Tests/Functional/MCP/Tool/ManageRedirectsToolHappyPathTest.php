<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ManageRedirectsTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ManageRedirectsToolHappyPathTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        'redirects',
    ];

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox']);

        parent::tearDown();
    }

    public function testListReturnsRedirectsWhenSurfaceIsAvailable(): void
    {
        $tool = $this->getService(ManageRedirectsTool::class);
        $redirectUid = $this->insertRedirectRecord(
            'audit.example.test',
            '/redirect-happy-path',
            'https://example.test/landing-page',
        );

        $result = $tool->execute([
            'action' => 'list',
            'source_host' => 'audit.example.test',
            'source_path' => '/redirect-happy-path',
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['returned']);
        self::assertFalse($data['hasMore']);
        self::assertCount(1, $data['redirects']);
        self::assertSame($redirectUid, $data['redirects'][0]['uid']);
        self::assertSame('https://example.test/landing-page', $data['redirects'][0]['target']);
        self::assertSame(303, $data['redirects'][0]['target_statuscode']);
        self::assertTrue($data['redirects'][0]['respect_query_parameters']);
    }

    public function testCreateActionReportsWorkspaceSafetyLimitation(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('Redirect Create Guard');
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'workspace_id' => $workspaceId,
            'action' => 'create',
            'source_host' => 'audit.example.test',
            'source_path' => '/blocked-create',
            'target' => 'https://example.test/blocked-create',
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_redirect is not workspace-capable', $this->getFirstTextContent($result));
    }

    public function testCreateActionWritesLiveRedirectWhenLocalModeAllowsLiveWrites(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'workspace_id' => 0,
            'action' => 'create',
            'source_host' => 'audit.example.test',
            'source_path' => '/local-create',
            'target' => 'https://example.test/local-create',
            'target_statuscode' => 302,
            'respect_query_parameters' => true,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('create', $data['action']);
        self::assertFalse($data['workspace_staged']);
        self::assertTrue($data['live_write']);
        self::assertIsInt($data['uid']);

        $row = $this->fetchRedirectRecord($data['uid']);
        self::assertIsArray($row);
        self::assertSame('/local-create', $row['source_path']);
        self::assertSame('https://example.test/local-create', $row['target']);
        self::assertSame(302, (int)$row['target_statuscode']);
        self::assertSame(1, (int)$row['respect_query_parameters']);
    }

    public function testCreateActionUsesLiveContextForNonWorkspaceRedirectWhenDraftWorkspaceIsActive(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $workspaceId = $this->createAndSwitchToWorkspace('Redirect Local Active Workspace');
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'action' => 'create',
            'source_host' => 'audit.example.test',
            'source_path' => '/local-create-active-workspace',
            'target' => 'https://example.test/local-create-active-workspace',
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertFalse($data['workspace_staged']);
        self::assertTrue($data['live_write']);
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        self::assertInstanceOf(BackendUserAuthentication::class, $backendUser);
        self::assertSame($workspaceId, $backendUser->workspace);

        $row = $this->fetchRedirectRecord($data['uid']);
        self::assertIsArray($row);
        self::assertSame('/local-create-active-workspace', $row['source_path']);
    }

    public function testDeleteActionReportsWorkspaceSafetyLimitation(): void
    {
        $workspaceId = $this->createAndSwitchToWorkspace('Redirect Delete Guard');
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'workspace_id' => $workspaceId,
            'action' => 'delete',
            'uid' => 123,
        ]);
        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_redirect is not workspace-capable', $this->getFirstTextContent($result));
    }

    public function testDeleteActionDeletesLiveRedirectWhenLocalModeAllowsLiveWrites(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $redirectUid = $this->insertRedirectRecord(
            'audit.example.test',
            '/local-delete',
            'https://example.test/local-delete',
        );
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'workspace_id' => 0,
            'action' => 'delete',
            'uid' => $redirectUid,
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($this->getFirstTextContent($result), true);
        self::assertIsArray($data);
        self::assertSame('delete', $data['action']);
        self::assertSame($redirectUid, $data['uid']);
        self::assertFalse($data['workspace_staged']);
        self::assertTrue($data['live_write']);

        $row = $this->fetchRedirectRecord($redirectUid);
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['deleted']);
    }

    public function testStrictSandboxBlocksLocalModeRedirectWrites(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['localUnsafeMode'] = 'on';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['mcpServer.strictSandbox'] = true;
        $tool = $this->getService(ManageRedirectsTool::class);

        $result = $tool->execute([
            'action' => 'create',
            'source_host' => 'audit.example.test',
            'source_path' => '/strict-sandbox-create',
            'target' => 'https://example.test/strict-sandbox-create',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('sys_redirect is not workspace-capable', $this->getFirstTextContent($result));
    }

    private function insertRedirectRecord(string $sourceHost, string $sourcePath, string $target): int
    {
        $now = time();
        $connection = $this->getConnectionForTable('sys_redirect');

        $connection->insert('sys_redirect', [
            'pid' => 0,
            'source_host' => $sourceHost,
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => 303,
            'force_https' => 0,
            'keep_query_parameters' => 0,
            'respect_query_parameters' => 1,
            'redirect_type' => 'default',
            'createdon' => $now,
            'updatedon' => $now,
            'creation_type' => 1,
            'createdby' => 1,
            'integrity_status' => 'no_conflict',
            'is_regexp' => 0,
            'protected' => 0,
            'disabled' => 0,
            'deleted' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRedirectRecord(int $uid): array|false
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
    }
}
