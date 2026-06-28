<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SolrIndexQueueTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class SolrIndexQueueToolTest extends AbstractFunctionalTest
{
    private SolrIndexQueueTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(SolrIndexQueueTool::class);
    }

    public function testSchemaDocumentsListAndRunActions(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'] ?? [];

        self::assertArrayHasKey('action', $properties);
        self::assertSame(['list', 'run'], $properties['action']['enum'] ?? null);
        self::assertArrayHasKey('taskUid', $properties);
        self::assertArrayHasKey('runs', $properties);
        self::assertContains('action', $schema['inputSchema']['required'] ?? []);

        $annotations = $schema['annotations'] ?? [];
        self::assertFalse($annotations['readOnlyHint'] ?? true);
        self::assertFalse($annotations['openWorldHint'] ?? true);
    }

    public function testRejectsUnknownAction(): void
    {
        $result = $this->tool->execute(['action' => 'scheduler:run']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Allowed: list, run', $this->getFirstTextContent($result));
    }

    public function testRunRejectsInvalidTaskUidBeforeCliExecution(): void
    {
        $result = $this->tool->execute([
            'action' => 'run',
            'taskUid' => 0,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('taskUid', $this->getFirstTextContent($result));
    }

    public function testRunRejectsTooManyRunsBeforeCliExecution(): void
    {
        $result = $this->tool->execute([
            'action' => 'run',
            'runs' => 11,
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('runs', $this->getFirstTextContent($result));
    }

    public function testRequiresAdminPrivileges(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $this->tool->execute(['action' => 'list']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', $this->getFirstTextContent($result));
    }
}
