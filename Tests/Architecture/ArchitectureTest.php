<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Layering rules verified at PHPStan time via phpat.
 *
 * Goal: keep the dependency graph one-way:
 *
 *   Controller   ──► MCP/Tool, Service, Http
 *   Command      ──► MCP, Service, Http
 *   MCP/Tool     ──► Service, Database, Utility, Event, Exception
 *   Service      ──► (TYPO3 core, no Controller, no Command, no MCP/Tool)
 *   Http         ──► Service, MCP, Middleware, Exception
 *
 * Anything that breaks the one-way flow trips a phpat rule and surfaces as
 * a phpstan error (no separate CI step).
 */
final class ArchitectureTest
{
    /**
     * Service layer must not call into the Controller layer — services
     * are reused by both HTTP controllers and CLI commands.
     */
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\Service'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Controller'));
    }

    /**
     * Same for MCP tools — they are the runtime API surface, not the
     * backend module.
     */
    public function testMcpToolsDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\MCP\Tool'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Controller'));
    }

    /**
     * Services must not pull in MCP tools — the dependency direction is
     * Tools → Services, never the reverse.
     */
    public function testServicesDoNotDependOnMcpTools(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\Service'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\MCP\Tool'));
    }

    /**
     * Services must not pull in console commands either — same reason.
     */
    public function testServicesDoNotDependOnCommands(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\Service'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Command'));
    }

    /**
     * MCP tools must not depend on console commands — keeps the tool
     * surface usable from both HTTP and CLI without circularity. The
     * inverse (Command → MCP tool) is the supported direction.
     */
    public function testMcpToolsDoNotDependOnCommands(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Hn\McpServer\MCP\Tool'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('Hn\McpServer\Command'));
    }
}
