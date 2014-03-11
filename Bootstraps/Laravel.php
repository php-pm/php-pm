<?php

namespace PHPPM\Bootstraps\Laravel;

use PHPPM\Bootstraps\BootstrapInterface;
use Stack\Builder;

/**
 * A default bootstrap for the Laravel framework
 */
class Laravel implements BootstrapInterface
{
    /**
     * @var string|null The application environment
     */
    protected $appenv;

    /**
     * Store the application
     *
     * @var Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $app;

    /**
     * Instantiate the bootstrap, storing the $appenv
     */
    public function __construct($appenv)
    {
        $this->appenv = $appenv;
    }

    /**
     * Create a Symfony application
     */
    public function getApplication()
    {
        if (file_exists(__DIR__ . '/autoload.php') && file_exists(__DIR__ . '/start.php')) {
            require_once __DIR__ . '/autoload.php';
            $this->app = require_once __DIR__ . '/start.php';
        }

        return $this->app;
    }

    /**
     * Return the StackPHP stack.
     */
    public function getStack(Builder $stack)
    {
        $sessionReject = $this->app->bound('session.reject') ? $this->app['session.reject'] : null;

        $stack
		    ->push('Illuminate\Cookie\Guard', $this->app['encrypter'])
			->push('Illuminate\Cookie\Queue', $this->app['cookie'])
            ->push('Illuminate\Session\Middleware', $this->app['session'], $sessionReject);

        return $stack;
    }
}
