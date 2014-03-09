<?php

namespace PHPPM\Bootstraps;

use Stack\Builder;

/**
 * All application bootstraps must implement this interface
 */
interface BootstrapInterface
{
    public function __construct($appenv);
    public function getApplication();
    public function getStack(Builder $stack);
}
