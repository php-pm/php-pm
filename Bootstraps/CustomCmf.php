<?php
/**
 * This file is bootstrap for the Custom CMF.
 *
 * @link      https://github.com/itcreator/custom-cmf for the canonical source repository
 */
 
namespace PHPPM\Bootstraps;

use Stack\Builder;

/**
 * This class is bootstrap for the Custom CMF.
 *
 * @author Vital Leshchyk <vitalleshchyk@gmail.com>
 */
class CustomCmf implements  BootstrapInterface
{
    /**
     * @var string|null The application environment
     */
    protected $appEnv;

    /**
     * Instantiate the bootstrap, storing the $appenv
     */
    public function __construct($appEnv)
    {
        $this->appEnv = $appEnv;
    }

    /**
     * Create a Custom CMF application
     *
     * @return \Cmf\System\Application
     */
    public function getApplication()
    {
        define ('ROOT', getcwd() . '/');

        if (!class_exists('Cmf\System\Application')) {
            require ROOT . 'vendor/autoload.php';
        }

        require ROOT . 'boot/bootstrap_www.php';

        $application = \Cmf\System\Application::getInstance();
        $application->init();

        return $application;
    }

    /**
     * Return the StackPHP stack.
     */
    public function getStack(Builder $stack)
    {
        return $stack;
    }
}
