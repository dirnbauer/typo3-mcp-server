<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\CreateSiteTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

final class CreateSiteToolTest extends AbstractFunctionalTest
{
    private CreateSiteTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->getService(CreateSiteTool::class);
    }

    public function testCreateDerivesDefaultFlagsFromIsoCodes(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'default-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('created', $data['status']);
        self::assertSame('default-flags-site', $data['identifier']);
        self::assertSame('us', $data['config']['languages'][0]['flag']);
        self::assertSame('de', $data['config']['languages'][1]['flag']);
    }

    public function testCreateAllowsExplicitFlagOverride(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'identifier' => 'explicit-flags-site',
            'rootPageId' => $this->getRootPageUid(),
            'base' => 'https://example.com/',
            'defaultLanguage' => [
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'iso-639-1' => 'en',
                'flag' => 'gb',
            ],
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'base' => '/de/',
                    'flag' => 'at',
                ],
            ],
        ]);

        $data = $this->extractJsonFromResult($result);
        self::assertSame('gb', $data['config']['languages'][0]['flag']);
        self::assertSame('at', $data['config']['languages'][1]['flag']);
    }
}
