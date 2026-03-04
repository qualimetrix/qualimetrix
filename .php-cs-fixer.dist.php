<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->notPath('Fixtures/Ast/invalid_syntax.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,

        // Strict typing
        'declare_strict_types' => true,
        'strict_param' => true,
        'strict_comparison' => true,

        // PHPDoc improvements
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],

        // Clean code
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'single_line_throw' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],

        // Modern PHP
        'modernize_types_casting' => true,
        'use_arrow_functions' => true,
        'get_class_to_class_keyword' => true,

        // Spacing
        'concat_space' => ['spacing' => 'one'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'not_operator_with_successor_space' => false,

        // Other
        'yoda_style' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'native_constant_invocation' => ['fix_built_in' => true, 'scope' => 'namespaced', 'strict' => true],
    ])
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache');
