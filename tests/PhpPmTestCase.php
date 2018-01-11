<?php

namespace PHPPM\Tests;

use PHPUnit\Framework\TestCase;

class PhpPmTestCase extends TestCase
{
    protected function getRequestHandlerMethod($method)
    {
        $mock = \Mockery::mock('PHPPM\\RequestHandler');

        return \Closure::bind(function () use ($method) {
            return call_user_func_array([$this, $method], func_get_args());
        }, $mock);
    }
}
