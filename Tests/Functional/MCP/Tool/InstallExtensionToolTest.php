<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\InstallExtensionTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class InstallExtensionToolTest extends AbstractFunctionalTest
{
    private InstallExtensionTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(InstallExtensionTool::class);
    }

    public function testSearchRequiresQueryParameter(): void
    {
        $result = $this->tool->execute(['action' => 'search']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Parameter "query" is required', $this->getFirstTextContent($result));
    }

    public function testRequireRejectsInvalidPackageName(): void
    {
        $result = $this->tool->execute([
            'action' => 'require',
            'package' => 'Invalid Package',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('Invalid package name', $this->getFirstTextContent($result));
    }

    public function testActivateRejectsForbiddenCharacters(): void
    {
        $result = $this->tool->execute([
            'action' => 'activate',
            'key' => 'news;rm -rf /',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('forbidden characters', $this->getFirstTextContent($result));
    }

    public function testToolRequiresAdminPrivileges(): void
    {
        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $this->tool->execute([
            'action' => 'search',
            'query' => 'news',
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin privileges', $this->getFirstTextContent($result));
    }
}
