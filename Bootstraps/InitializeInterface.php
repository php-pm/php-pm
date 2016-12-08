<?php

namespace PHPPM\Bootstraps;


interface InitializeInterface
{
    public function initialize($appenv, $debug);
}