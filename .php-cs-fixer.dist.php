<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->exclude('public')
    ->in(__DIR__)
;

return $config;
