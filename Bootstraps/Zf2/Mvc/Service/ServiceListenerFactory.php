<?php

namespace PHPPM\Bootstraps\Zf2\Mvc\Service;

use Zend\Mvc;
use Zend\ServiceManager\ServiceLocatorInterface;
use PHPPM\Bootstraps\Zf2\Mvc\Application;
use Zend\Http\PhpEnvironment\Response;
use Zend\Http\PhpEnvironment\Request;

class ServiceListenerFactory extends Mvc\Service\ServiceListenerFactory
{
    public function __construct()
    {
        $this->defaultServiceConfig['factories'] = array_merge($this->defaultServiceConfig['factories'], array(
            'ViewHelperManager' => 'PHPPM\Bootstraps\Zf2\Mvc\Service\ViewHelperManagerFactory',

            'Router' => function(ServiceLocatorInterface $serviceLocator) {
                $config = $serviceLocator->has('Config') ? $serviceLocator->get('Config') : array();

                // Defaults
                $routerClass = 'Zend\Mvc\Router\Http\TreeRouteStack';
                $routerConfig = isset($config['router']) ? $config['router'] : array();

                // Obtain the configured router class, if any
                if (isset($routerConfig['router_class']) && class_exists($routerConfig['router_class'])) {
                    $routerClass = $routerConfig['router_class'];
                }

                // Inject the route plugins
                if (!isset($routerConfig['route_plugins'])) {
                    $routePluginManager = $serviceLocator->get('RoutePluginManager');
                    $routerConfig['route_plugins'] = $routePluginManager;
                }

                // Obtain an instance
                $factory = sprintf('%s::factory', $routerClass);
                return call_user_func($factory, $routerConfig);
            },

            'Request' => function(ServiceLocatorInterface $serviceLocator) {
                return new Request();
            },

            'Response' => function(ServiceLocatorInterface $serviceLocator) {
                return new Response();
            },

            'Application' => function(ServiceLocatorInterface $serviceLocator) {
                return new Application($serviceLocator->get('Config'), $serviceLocator);
            },

            'ViewManager' => function(ServiceLocatorInterface $serviceLocator) {
                return $serviceLocator->get('HttpViewManager');
            },
        ));
    }
}
