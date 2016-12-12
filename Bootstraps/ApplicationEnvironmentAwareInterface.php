<?php

namespace PHPPM\Bootstraps;


interface ApplicationEnvironmentAwareInterface
{
    public function initialize($appenv, $debug);
}