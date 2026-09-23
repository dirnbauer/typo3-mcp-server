<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$finder = $config->getFinder();
if ($finder instanceof Finder) {
    $finder
        ->exclude([
            '.Build',
            'config',
            'packages',
            'public',
            'typo3temp',
            'var',
        ])
        ->in(__DIR__);
}

return $config;
