<?php

namespace PHPPM\Tests;

use PHPPM\ProcessManager;

/**
 * A class to mock the process manager and prevent it from actually binding to web, for testing purposes
 */
class MockProcessManager extends ProcessManager
{
    public function startListening()
    {
    }
}
