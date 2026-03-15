<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return array_map(
    static fn (string $source) => [
        'provider' => SvgIconProvider::class,
        'source' => $source,
    ],
    [
        'module-mcp-server' => 'EXT:mcp_server/Resources/Public/Icons/module-mcp-server.svg',
    ]
);
