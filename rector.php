<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
    ])
    ->withSkip([
        __DIR__ . '/Resources',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_82,
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    // importShortClasses: false keeps \Exception etc. and matches TYPO3 Coding Standards
    // (otherwise php-cs-fixer reverts imports and CI `rector --dry-run` never goes green).
    ->withImportNames(importShortClasses: false);
