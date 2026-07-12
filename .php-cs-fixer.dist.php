<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->exclude([
        '.Build',
        'config',
        'packages',
        'public',
        'Resources/Private/PHP/vendor',
        'typo3temp',
        'var',
    ])
    ->in(__DIR__)
;

return $config;
