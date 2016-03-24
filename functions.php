<?php

use PHPPM\ProcessSlave;

/**
 * Adds a file path to the watcher list of PPM's master process.
 *
 * If you have a custom template engine, cache engine or something dynamic
 * you probably want to call this function to let PPM know,
 * that you want to restart all workers when this file has changed.
 *
 * @param string $path
 */
function ppm_register_file($path)
{
    ProcessSlave::$slave->registerFile($path);
}