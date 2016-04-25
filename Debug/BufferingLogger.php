<?php

namespace PHPPM\Debug;

use Psr\Log\AbstractLogger;

/**
 * Class BufferingLogger
 *
 * A buffering logger that stacks logs for later.
 * Based on Symfony's BufferingLogger
 *
 * @package PHPFPM\Debug
 */
class BufferingLogger extends AbstractLogger
{
    private $logs = [];

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