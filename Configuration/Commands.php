<?php

use Hn\McpServer\Command\McpServerCommand;
use Hn\McpServer\Command\McpTestCommand;
use Hn\McpServer\Command\McpToolListCommand;
use Hn\McpServer\Command\McpToolRunCommand;
use Hn\McpServer\Command\OAuthManageCommand;
use Hn\McpServer\Command\Tool\ApplyShadcnPresetToolCommand;
use Hn\McpServer\Command\Tool\GetCapabilitiesToolCommand;
use Hn\McpServer\Command\Tool\GetPageToolCommand;
use Hn\McpServer\Command\Tool\GetPageTreeToolCommand;
use Hn\McpServer\Command\Tool\GetPreviewUrlToolCommand;
use Hn\McpServer\Command\Tool\GetTableSchemaToolCommand;
use Hn\McpServer\Command\Tool\ListTablesToolCommand;
use Hn\McpServer\Command\Tool\ListWorkspacesToolCommand;
use Hn\McpServer\Command\Tool\PublishWorkspaceToolCommand;
use Hn\McpServer\Command\Tool\ReadTableToolCommand;
use Hn\McpServer\Command\Tool\RenderRecordToolCommand;
use Hn\McpServer\Command\Tool\SearchToolCommand;
use Hn\McpServer\Command\Tool\SiteSetToolCommand;
use Hn\McpServer\Command\Tool\WriteTableToolCommand;

return [
    'mcp:server' => [
        'class' => McpServerCommand::class,
        'schedulable' => false,
    ],
    'mcp:test' => [
        'class' => McpTestCommand::class,
        'schedulable' => false,
    ],
    'mcp:oauth' => [
        'class' => OAuthManageCommand::class,
        'schedulable' => false,
    ],

    // Generic tool runner + discovery
    'mcp:tool' => [
        'class' => McpToolRunCommand::class,
        'schedulable' => false,
    ],
    'mcp:tool:list' => [
        'class' => McpToolListCommand::class,
        'schedulable' => false,
    ],

    // Per-tool ergonomic shortcuts. Add a new entry whenever a new MCP tool
    // is added — the typo3-mcp-cli skill walks through the 30-second recipe.
    'mcp:read-table' => [
        'class' => ReadTableToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:write-table' => [
        'class' => WriteTableToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:get-page' => [
        'class' => GetPageToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:get-page-tree' => [
        'class' => GetPageTreeToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:search' => [
        'class' => SearchToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:list-tables' => [
        'class' => ListTablesToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:get-table-schema' => [
        'class' => GetTableSchemaToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:list-workspaces' => [
        'class' => ListWorkspacesToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:publish-workspace' => [
        'class' => PublishWorkspaceToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:get-capabilities' => [
        'class' => GetCapabilitiesToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:render-record' => [
        'class' => RenderRecordToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:get-preview-url' => [
        'class' => GetPreviewUrlToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:site-set' => [
        'class' => SiteSetToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:apply-shadcn-preset' => [
        'class' => ApplyShadcnPresetToolCommand::class,
        'schedulable' => false,
    ],
    'mcp:install-editor-skills' => [
        'class' => \Hn\McpServer\Command\InstallEditorSkillsCommand::class,
        'schedulable' => false,
    ],
];
