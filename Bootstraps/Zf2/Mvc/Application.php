<?php

namespace PHPPM\Bootstraps\Zf2\Mvc;

use Zend\ServiceManager\ServiceManager;
use Zend\Mvc;

class Application extends Mvc\Application
{

    protected $defaultListeners = array(
        'RouteListener',
        'DispatchListener',
        'ViewManager',
    );

    public function __construct($configuration, ServiceManager $serviceManager)
    {
        $this->configuration  = $configuration;
        $this->serviceManager = $serviceManager;

        $this->setEventManager($serviceManager->get('EventManager'));
    }

    public function bootstrap(array $listeners = array())
    {
        $services = $this->serviceManager;
        $events = $this->events;

        $listeners = array_unique(array_merge($this->defaultListeners, $listeners));

        foreach ($listeners as $listener) {
            $events->attach($services->get($listener));
        }

        // Setup MVC Event
        $this->event = $event  = new Mvc\MvcEvent();
        $event->setTarget($this);
        $event->setApplication($this)
              ->setRouter($services->get('Router'));

        // Trigger bootstrap events
        $events->trigger(Mvc\MvcEvent::EVENT_BOOTSTRAP, $event);
        return $this;
    }

    /**
     * @param \Zend\Http\PhpEnvironment\Request $request
     * @param \Zend\Http\PhpEnvironment\Response $response
     */
    public function run()
    {
        $this->request = func_get_arg(0);
        $this->response = func_get_arg(1);

        return parent::run();
    }
}