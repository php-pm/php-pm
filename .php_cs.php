<?php

$finder = PhpCsFixer\Finder::create()
    ->in('src')
;

return PhpCsFixer\Config::create()
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'RemoveDebugStatements/dump' => true,
    ])
    ->registerCustomFixers([new Drew\DebugStatementsFixers\Dump()])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setUsingCache(false)
;
