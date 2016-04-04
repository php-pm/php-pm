<?php

namespace PHPPM\Tests;

class PhpPmTestCase extends \PHPUnit_Framework_TestCase
{
    protected function getProcessManagerMethod($method)
    {
        $mock = \Mockery::mock('PHPPM\\ProcessManager');

        return \Closure::bind(function() use ($method) {
            return call_user_func_array([$this, $method], func_get_args());
        }, $mock);
    }
}

