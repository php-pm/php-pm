<?php

namespace PHPPM\Bootstraps;

/**
 * All application bootstraps must implement this interface
 */
interface BootstrapInterface
{
    public function getApplication();
    public function getStaticDirectory();
}
