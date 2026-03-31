<?php

$finder = Symfony\Component\Finder\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRules([
        // PSR-1 & PSR-12 compliance
        '@PSR12' => true,
        
        // Additional PSR-1 enforcement
        'class_definition' => [
            'single_line' => false,
            'multi_line_extends_each_single_line' => true,
        ],
        'constant_case' => true, // PSR-1: Constants MUST be declared in all upper case
        'lowercase_keywords' => true, // PSR-1: PHP keywords MUST be in lower case
        'lowercase_static_reference' => true, // PSR-1: The static keyword MUST be declared in lower case
        
        // Additional PSR-12 enforcement
        'declare_strict_types' => false, // Optional: uncomment to enforce strict types
        'function_declaration' => true,
        'visibility_required' => true, // PSR-12: Visibility MUST be declared on all properties and methods
        
        // Code quality rules that help maintain PSR compliance
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'length'],
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => true,
        'trailing_comma_in_multiline' => true,
        'phpdoc_scalar' => true,
        'unary_operator_spaces' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_var_without_name' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        'single_trait_insert_per_statement' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
