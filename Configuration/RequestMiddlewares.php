<?php

declare(strict_types=1);

use Hn\McpServer\Middleware\McpServerMiddleware;
use Hn\McpServer\Middleware\BackendUserConfigurationMiddleware;

return [
    'frontend' => [
        'hn-mcp-server/routes' => [
            'target' => McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    'backend' => [
        'hn-mcp-server/backend-user-configuration' => [
            'target' => BackendUserConfigurationMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/backend-module-validator',
            ],
        ],
        'hn-mcp-server/routes' => [
            'target' => McpServerMiddleware::class,
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
