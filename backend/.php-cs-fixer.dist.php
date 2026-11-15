<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    // Applied migrations are immutable and must not be reformatted afterwards.
    ->exclude('migrations')
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
