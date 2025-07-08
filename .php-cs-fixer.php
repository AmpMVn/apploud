<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@Symfony' => true,
    '@PHP83Migration' => true,
    '@PSR12' => true,
    '@PhpCsFixer' => true,
    'array_syntax' => ['syntax' => 'short'],
    'ordered_imports' => [
        'sort_algorithm' => 'alpha',
        'imports_order' => ['class', 'function', 'const'],
    ],
    'no_unused_imports' => true,
    'declare_strict_types' => true,
    'strict_comparison' => true,
    'strict_param' => true,
    'no_superfluous_phpdoc_tags' => [
        'allow_mixed' => true,
        'allow_unused_params' => false,
    ],
    'fully_qualified_strict_types' => true,
    'native_function_invocation' => [
        'include' => ['@compiler_optimized'],
        'scope' => 'namespaced',
    ],
    'phpdoc_types' => true,
    'phpdoc_to_comment' => false,
    'phpdoc_annotation_without_dot' => true,
    'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
    'no_alternative_syntax' => true,
    'no_trailing_comma_in_singleline' => true,
    'trailing_comma_in_multiline' => [
        'elements' => ['arrays', 'arguments', 'parameters'],
    ],
    'global_namespace_import' => [
        'import_classes' => true,
        'import_constants' => false,
        'import_functions' => false,
    ],
    'php_unit_strict' => true,
    'void_return' => true,
    'combine_consecutive_issets' => true,
    'combine_consecutive_unsets' => true,
    'explicit_string_variable' => true,
])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;