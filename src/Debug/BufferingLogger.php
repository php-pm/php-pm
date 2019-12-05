<?php

/*
 * Based on Symfony's BufferingLogger
 *
 * Copyright (c) 2004-2016 Fabien Potencier
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace PHPPM\Debug;

use Psr\Log\AbstractLogger;

/**
 * A buffering logger that stacks logs for later.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class BufferingLogger extends AbstractLogger
{
    private $logs = [];

    /**
     * @return BufferingLogger|\Symfony\Component\Debug\BufferingLogger|\Symfony\Component\ErrorHandler\BufferingLogger
     *
     * Check if we are using symfony/debug >= 2.8.
     * In symfony/debug <= 2.7, \Symfony\Component\Debug\BufferingLogger isn't available.
     * Laravel 5.1 depends on symfony/debug 2.7.*, so to support Laravel 5.1 we supply a custom BufferingLogger
     * when Symfony's BufferingLogger isn't available.
     */
    public static function create()
    {
        if (class_exists('\Symfony\Component\ErrorHandler\BufferingLogger')) {
            return new \Symfony\Component\ErrorHandler\BufferingLogger();
        } elseif (class_exists('\Symfony\Component\Debug\BufferingLogger')) {
            // deprecated as of Symfony 4.4
            return new \Symfony\Component\Debug\BufferingLogger();
        }

        return new static();
    }

    public function log($level, $message, array $context = [])
    {
        $this->logs[] = [$level, $message, $context];
    }

    public function cleanLogs()
    {
        $logs = $this->logs;
        $this->logs = [];

        return $logs;
    }
}
