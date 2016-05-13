<?php

namespace PHPPM\Debug;

use Psr\Log\AbstractLogger;

/**
 * Class BufferingLogger
 *
 * A buffering logger that stacks logs for later.
 * Based on Symfony's BufferingLogger
 */
class BufferingLogger extends AbstractLogger
{
    private $logs = [];

    /**
     * @return BufferingLogger|\Symfony\Component\Debug\BufferingLogger
     *
     * Check if we are using symfony/debug >= 2.8.
     * In symfony/debug <= 2.7, \Symfony\Component\Debug\BufferingLogger isn't available.
     * Laravel 5.1 depends on symfony/debug 2.7.*, so to support Laravel 5.1 we supply a custom BufferingLogger
     * when Symfony's BufferingLogger isn't available.
     */
    public static function create()
    {
        if (class_exists('\Symfony\Component\Debug\BufferingLogger')) {
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
