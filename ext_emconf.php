<?php

$EM_CONF['mcp_server'] = [
    'title' => 'MCP Server',
    'description' => 'TYPO3 extension that provides a Model Context Protocol (MCP) server for interacting with TYPO3 pages and records',
    'category' => 'module',
    'author' => 'Marco Pfeiffer',
    'author_email' => 'marco@hauptsache.net',
    'state' => 'beta',
    'version' => '0.8.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.6-14.99.99',
            'workspaces' => '14.3.6-14.99.99',
            'php' => '8.4.0-8.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
