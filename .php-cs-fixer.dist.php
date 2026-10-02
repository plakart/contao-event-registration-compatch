<?php

declare(strict_types=1);

$dirs = array_filter(
    [__DIR__.'/src', __DIR__.'/tests', __DIR__.'/contao'],
    'is_dir',
);

$finder = PhpCsFixer\Finder::create()->in($dirs);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
