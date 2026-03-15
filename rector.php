<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$typo3LevelSet = defined(Typo3LevelSetList::class . '::UP_TO_TYPO3_14')
    ? constant(Typo3LevelSetList::class . '::UP_TO_TYPO3_14')
    : Typo3LevelSetList::UP_TO_TYPO3_13;

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
        $typo3LevelSet,
    ])
    ->withImportNames();
