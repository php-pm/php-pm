<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

trait DaemonTrait
{
    /**
     * Split into a new process. Returns true when it's the child process and false
     * for the parent process.
     *
     * Borrowed from https://github.com/phlib/console-process/blob/master/src/Command/DaemonCommand.php
     *
     * @return bool
     */
    private function daemonize()
    {
        // prevent permission issues
        umask(0);
        $pid = pcntl_fork();
        if ($pid == -1) {
            /* fork failed */
            throw new \RuntimeException('Failed to fork the daemon.');
        } elseif ($pid) {
            /* close the parent */
            return false;
        }
        // make ourselves the session leader
        if (posix_setsid() == -1) {
            throw new \RuntimeException('Failed to become a daemon.');
        }
        return true;
    }

    /**
     * Borrowed from https://github.com/phlib/console-process/blob/master/src/Command/DaemonCommand.php
     * @param InputInterface $input
     * @return InputInterface
     */
    private function recreateInput(InputInterface $input)
    {
        return clone $input;
    }

    /**
     * Borrowed from https://github.com/phlib/console-process/blob/master/src/Command/DaemonCommand.php
     * @param OutputInterface $output
     * @return OutputInterface
     */
    private function recreateOutput(OutputInterface $output, $logfile)
    {
        $verbosityLevel = $output->getVerbosity();

        if ($logfile) {
            $newInstance = new StreamOutput(fopen($logfile, 'a'));
        }
        else {
            $newInstance = new NullOutput();
        }

        $newInstance->setVerbosity($verbosityLevel);
        return $newInstance;
    }
}
