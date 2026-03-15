<?php

declare(strict_types=1);

use a9f\Fractor\Configuration\FractorConfiguration;
use a9f\Typo3Fractor\Set\Typo3LevelSetList;

$typo3LevelSet = defined(Typo3LevelSetList::class . '::UP_TO_TYPO3_14')
    ? constant(Typo3LevelSetList::class . '::UP_TO_TYPO3_14')
    : Typo3LevelSetList::UP_TO_TYPO3_13;

return FractorConfiguration::configure()
    ->withPaths([
        __DIR__ . '/Configuration/',
        __DIR__ . '/Resources/',
    ])
    ->withSets([
        $typo3LevelSet,
    ]);
