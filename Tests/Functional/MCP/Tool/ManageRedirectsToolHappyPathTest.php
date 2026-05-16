<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ManageRedirectsTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class ManageRedirectsToolHappyPathTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        'redirects',
    ];

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
}
