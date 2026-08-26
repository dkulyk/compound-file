<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP81Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'native_function_invocation' => false,
        'no_superfluous_phpdoc_tags' => false,
        'ordered_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
