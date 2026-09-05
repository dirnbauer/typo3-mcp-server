<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->exclude([
        '.Build',
        'config',
        'packages',
        'public',
        'typo3temp',
        'var',
    ])
    ->in(__DIR__)
;

return $config;
