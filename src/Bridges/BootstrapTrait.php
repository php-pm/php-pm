<?php

namespace PHPPM\Bridges;

use PHPPM\Bootstraps\ApplicationEnvironmentAwareInterface;

trait BootstrapTrait
{
    private $middleware;

    /**
     * Bootstrap application environment
     *
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @param string $appenv
     * @param boolean $debug If debug is enabled
     */
    private function bootstrapApplicationEnvironment($appBootstrap, $appenv, $debug)
    {
        $appBootstrap = $this->normalizeBootstrapClass($appBootstrap);

        $this->middleware = new $appBootstrap;
        if ($this->middleware instanceof ApplicationEnvironmentAwareInterface) {
            $this->middleware->initialize($appenv, $debug);
        }
    }

    /**
     * @param $appBootstrap
     * @return string
     * @throws \RuntimeException
     */
    private function normalizeBootstrapClass($appBootstrap)
    {
        $appBootstrap = str_replace('\\\\', '\\', $appBootstrap);

        $bootstraps = [
            $appBootstrap,
            '\\' . $appBootstrap,
            '\\PHPPM\Bootstraps\\' . ucfirst($appBootstrap)
        ];

        foreach ($bootstraps as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return $appBootstrap;
    }
}
