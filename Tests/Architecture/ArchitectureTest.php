<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ArchitectureTest
{
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Controller'));
    }

    public function testMcpToolsDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\MCP\Tool'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Controller'));
    }
}
