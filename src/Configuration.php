<?php

namespace PHPPM;

use Symfony\Component\Process\PhpExecutableFinder;

class Configuration
{
    /**
     * The array of options, should only represent the defaults and options seen in ppm.json
     *
     * @var array
     */
    private $options;

    /**
     * The array of arguments, as these are passed to a new Configuration instance during reload
     * This preserves any settings that have been overridden by a program argument
     *
     * @var array
     */
    private $arguments;

    /**
     * Returns a mapping of configuration options, with default values and descriptors for CLI
     *
     * @return array
     */
    public static function getMapping()
    {
        return [
            'bridge' => [
                'description' => 'Bridge for converting React PSR7 requests to target framework.',
                'default' => 'HttpKernel',
            ],
            'config' => [
                'description' => 'Path to config file.',
                'default' => '',
                'shortcut' => 'c',
            ],
            'host' => [
                'description' => 'Load-Balancer host. Default is 127.0.0.1',
                'default' => '127.0.0.1',
            ],
            'port' => [
                'description' => 'Load-Balancer port. Default is 8080',
                'default' => 8080,
            ],
            'workers' => [
                'description' => 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.',
                'default' => 8,
            ],
            'app-env' => [
                'description' => 'The environment that your application will use to bootstrap (if any)',
                'default' => 'dev',
            ],
            'debug' => [
                'description' => 'Enable/Disable debugging so that your application is more verbose, enables also hot-code reloading. 1|0',
                'default' => false,
            ],
            'logging' => [
                'description' => 'Enable/Disable http logging to stdout. 1|0',
                'default' => true,
            ],
            'static-directory' => [
                'description' => 'Static files root directory, if not provided static files will not be served',
                'default' => '',
            ],
            'max-requests' => [
                'description' => 'Max requests per worker until it will be restarted',
                'default' => 1000,
            ],
            'populate-server-var' => [
                'description' => 'If a worker application uses $_SERVER var it needs to be populated by request data 1|0',
                'default' => true,
            ],
            'bootstrap' => [
                'description' => 'Class responsible for bootstrapping the application',
                'default' => 'PHPPM\Bootstraps\Symfony',
            ],
            'cgi-path' => [
                'description' => 'Full path to the php-cgi executable',
                'default' => null,
            ],
            'socket-path' => [
                'description' => 'Path to a folder where socket files will be placed. Relative to working-directory or cwd()',
                'default' => '.ppm/run/',
            ],
            'pidfile' => [
                'description' => 'Path to a file where the pid of the master process is going to be stored',
                'default' => '.ppm/ppm.pid',
            ],
            'reload-timeout' => [
                'description' => 'The number of seconds to wait before force closing a worker during a reload, or -1 to disable. Default: 30',
                'default' => 30,
            ],
        ];
    }

    /**
     * Returns the default configuration options table
     *
     * @return array
     */
    public static function getDefaults()
    {
        return array_map(function ($mapping) {
            return $mapping['default'];
        }, self::getMapping());
    }

    /**
     * Load a config from a file path
     *
     * @param string $path
     * @return Configuration
     */
    public static function loadFromPath($path)
    {
        $content = file_get_contents($path);
        $config = json_decode($content, true);
        return new Configuration($config);
    }

    /**
     * Configuration constructor.
     *
     * @param array $options
     * @param array $arguments
     */
    public function __construct($options = [], $arguments = [])
    {
        $this->options = array_merge(self::getDefaults(), $options);
        $this->arguments = $arguments;
    }

    /**
     * Attempt to resolve a cgi-path value automatically.
     *
     * @return null|string
     */
    public function resolvePhpCgiPath()
    {
        $executableFinder = new PhpExecutableFinder();
        $binary = $executableFinder->find();

        $cgiPaths = [
            $binary . '-cgi', //php7.0 -> php7.0-cgi
            str_replace('php', 'php-cgi', $binary), //php7.0 => php-cgi7.0
        ];

        foreach ($cgiPaths as $cgiPath) {
            $path = trim(`which $cgiPath`);
            if ($path) {
                return $path;
            }
        }

        return null;
    }

    public function tryResolvePhpCgiPath()
    {
        $this->arguments['cgi-path'] = $this->resolvePhpCgiPath();
    }

    /**
     * Set the arguments array
     *
     * @param array $arguments
     */
    public function setArguments($arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Get the arguments array
     *
     * @return array
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @param string $option
     * @return mixed
     */
    public function getOption($option)
    {
        return isset($this->arguments[$option]) ? $this->arguments[$option] : $this->options[$option];
    }

    /**
     * @return string
     */
    public function getBridge()
    {
        return $this->getOption('bridge');
    }

    /**
     * @return string
     */
    public function getAppEnv()
    {
        return $this->getOption('app-env');
    }

    /**
     * @return string
     */
    public function getAppBootstrap()
    {
        return $this->getOption('bootstrap');
    }

    /**
     * @return bool
     */
    public function isPopulateServer()
    {
        return (bool) $this->getOption('populate-server-var');
    }

    /**
     * @return bool
     */
    public function isLogging()
    {
        return (bool) $this->getOption('logging');
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return (bool) $this->getOption('debug');
    }

    /**
     * @return string
     */
    public function getStaticDirectory()
    {
        return $this->getOption('static-directory');
    }

    /**
     * @return string
     */
    public function getReloadTimeout()
    {
        return $this->getOption('reload-timeout');
    }

    /**
     * @return int
     */
    public function getMaxRequests()
    {
        return (int) $this->getOption('max-requests');
    }

    /**
     * @return int
     */
    public function getSlaveCount()
    {
        return (int) $this->getOption('workers');
    }

    /**
     * @return string
     */
    public function getSocketPath()
    {
        return $this->getOption('socket-path');
    }

    /**
     * @return string
     */
    public function getPIDFile()
    {
        return $this->getOption('pidfile');
    }

    /**
     * @return string|null
     */
    public function getPhpCgiExecutable()
    {
        return $this->getOption('cgi-path');
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return (int) $this->getOption('port');
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->getOption('host');
    }

    /**
     * @return string
     */
    public function getConfigPath()
    {
        return $this->getOption('config');
    }

    /**
     * Return the array representation of this config
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->options, $this->arguments);
    }
}
