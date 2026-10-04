<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    ->exclude('fixtures')
    ->name('pn-invoice')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'native_function_invocation' => false,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
        'phpdoc_align' => false,
        'void_return' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
