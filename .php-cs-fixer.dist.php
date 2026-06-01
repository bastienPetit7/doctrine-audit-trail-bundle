<?php

declare(strict_types=1);

return new PhpCsFixer\Config()
    ->setFinder(
        PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests'])->append([__FILE__])
    )
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP82Migration' => true,
        '@PHP82Migration:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // Keep the it_should_..._when_... behavioural test naming convention.
        'php_unit_method_casing' => false,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'php_unit_strict' => true,
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
;
