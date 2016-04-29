<?php

namespace PHPPM\Bootstraps;

/**
 * All application bootstraps must implement this interface
 */
interface BootstrapInterface
{
    public function __construct($appenv, $debug);
    public function getApplication();
    public function getStaticDirectory();
}
