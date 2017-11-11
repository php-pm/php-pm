<?php

namespace PHPPM\Tests;

use PHPUnit\Framework\TestCase;

class PhpPmTestCase extends TestCase
{
    protected function getProcessManagerMethod($method)
    {
        $mock = \Mockery::mock('PHPPM\\ProcessManager');

        return \Closure::bind(function() use ($method) {
            return call_user_func_array([$this, $method], func_get_args());
        }, $mock);
    }
}
