<?php

require_once __DIR__ . '/vendor/autoload.php';

$finder = (new PhpCsFixer\Finder())
    ->in('src')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'native_function_invocation' => ['strict' => true, 'include' => ['@internal']],
    ])
    // ->registerCustomFixers([new Drew\DebugStatementsFixers\Dump()])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(false)
;
