<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->in(__DIR__)
;

return PhpCsFixer\Config::create()
    ->setRules(array(
        '@Symfony' => true,
        'ordered_imports' => true,                      // Order "use" alphabetically
        'array_syntax' => ['syntax' => 'short'],        // Replace array() by []
        'no_useless_return' => true,                    // Keep return null;
        'phpdoc_order' => true,                         // Clean up the /** php doc */
        'linebreak_after_opening_tag' => true,
        'no_multiline_whitespace_before_semicolons' => true,
        'phpdoc_add_missing_param_annotation' => true,
    ))
    ->setUsingCache(false)
    ->setFinder($finder)
;
