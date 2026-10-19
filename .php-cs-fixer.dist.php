<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP81Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'no_extra_blank_lines' => true,
        'no_superfluous_phpdoc_tags' => false,
        'no_unused_imports' => true,
        'ordered_class_elements' => [
            'order' => ['use_trait', 'case', 'constant', 'property'],
            'sort_algorithm' => 'none',
        ],
        'ordered_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
