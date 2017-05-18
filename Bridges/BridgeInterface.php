<?php

namespace PHPPM\Bridges;

use Interop\Http\ServerMiddleware\DelegateInterface;
use React\EventLoop\LoopInterface;

interface BridgeInterface extends DelegateInterface
{
    /**
     * Bootstrap an application
     *
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @param string $appenv
     * @param boolean $debug If debug is enabled
     * @param LoopInterface $loop
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv, $debug, LoopInterface $loop);
}
