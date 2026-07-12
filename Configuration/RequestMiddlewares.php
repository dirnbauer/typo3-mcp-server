<?php

declare(strict_types=1);

use Hn\McpServer\Integration\ApiCore\AbilitiesApiPolicyMiddleware;
use Hn\McpServer\Middleware\BackendUserConfigurationMiddleware;
use Hn\McpServer\Middleware\McpServerMiddleware;
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

$middlewares = [
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

if (
    ExtensionManagementUtility::isLoaded('sg_apicore')
    && ExtensionManagementUtility::isLoaded('abilities')
    && class_exists(ApiRegistry::class)
    && class_exists(AbilitiesRegistry::class)
) {
    $middlewares['frontend']['hn-mcp-server/abilities-api-policy'] = [
        'target' => AbilitiesApiPolicyMiddleware::class,
        'description' => 'Reasserts the secure abilities REST/OpenAPI policy before API Core reads it',
        'after' => [
            'typo3/cms-frontend/site',
        ],
        'before' => [
            'sgalinski/sg-apicore/api-cors',
        ],
    ];
}

return $middlewares;
