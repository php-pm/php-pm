<?php
declare(ticks = 1);

namespace PHPPM;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\ProcessUtils;

class ProcessManager
{
    /**
     * @var array
     */
    protected $slaves = [];

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var \React\Socket\Server
     */
    protected $controller;

    /**
     * @var \React\Socket\Server
     */
    protected $web;

    /**
     * @var \React\SocketClient\TcpConnector
     */
    protected $tcpConnector;

    /**
     * @var int
     */
    protected $slaveCount = 1;

    /**
     * @var bool
     */
    protected $waitForSlaves = true;

    /**
     * Whether the server is up and thus creates new slaves when they die or not.
     *
     * @var bool
     */
    protected $isRunning = false;

    /**
     * @var int
     */
    protected $index = 0;

    /**
     * @var string
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

    /**
     * @var string|null
     */
    protected $appenv;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var bool
     */
    protected $logging = true;

    /**
     * @var string
     */
    protected $host = '127.0.0.1';

    /**
     * @var int
     */
    protected $port = 8080;

    /**
     * Whether the server is in the reload phase.
     *
     * @var bool
     */
    protected $inReload = false;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * How many requests each worker is allowed to handle until it will be restarted.
     *
     * @var int
     */
    protected $maxRequests = 1000;

    /**
     * Full path to the php-cgi executable. If not set, we try to determine the
     * path automatically.
     *
     * @var string
     */
    protected $phpCgiExecutable = false;

    /**
     * If a worker is allowed to handle more than one request at the same time.
     * This can lead to issues when the application does not support it (like when they operate on globals at the same time)
     *
     * @var bool
     */
    protected $concurrentRequestsPerWorker = false;

    protected $filesToTrack = [];
    protected $filesLastMTime = [];

    /**
     * ProcessManager constructor.
     *
     * @param OutputInterface $output
     * @param int             $port
     * @param string          $host
     * @param int             $slaveCount
     */
    function __construct(OutputInterface $output, $port = 8080, $host = '127.0.0.1', $slaveCount = 8)
    {
        $this->output = $output;
        $this->slaveCount = $slaveCount;
        $this->host = $host;
        $this->port = $port;
        register_shutdown_function([$this, 'signal']);
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     */
    public function signal()
    {
        //this method is also called during startup when something crashed, so
        //make sure we don't operate on nulls.
        $this->output->writeln('<info>Termination received, exiting.</info>');
        if ($this->controller) {
            @$this->controller->shutdown();
        }
        if ($this->web) {
            @$this->web->shutdown();
        }
        if ($this->loop) {
            $this->loop->tick();
            $this->loop->stop();
        }

        foreach ($this->slaves as $slave) {
            if (is_resource($slave['process'])) {
                proc_terminate($slave['process']);
            }

            if ($slave['pid']) {
                //make sure its dead
                posix_kill($slave['pid'], SIGKILL);
            }
        }
        exit;
    }

    /**
     * @param int $maxRequests
     */
    public function setMaxRequests($maxRequests)
    {
        $this->maxRequests = $maxRequests;
    }

    /**
     * @param string $phpCgiExecutable
     */
    public function setPhpCgiExecutable($phpCgiExecutable)
    {
        $this->phpCgiExecutable = $phpCgiExecutable;
    }

    /**
     * @param boolean $concurrentRequestsPerWorker
     */
    public function setConcurrentRequestsPerWorker($concurrentRequestsPerWorker)
    {
        $this->concurrentRequestsPerWorker = $concurrentRequestsPerWorker;
    }

    /**
     * @param string $bridge
     */
    public function setBridge($bridge)
    {
        $this->bridge = $bridge;
    }

    /**
     * @return string
     */
    public function getBridge()
    {
        return $this->bridge;
    }

    /**
     * @param string $appBootstrap
     */
    public function setAppBootstrap($appBootstrap)
    {
        $this->appBootstrap = $appBootstrap;
    }

    /**
     * @return string
     */
    public function getAppBootstrap()
    {
        return $this->appBootstrap;
    }

    /**
     * @param string|null $appenv
     */
    public function setAppEnv($appenv)
    {
        $this->appenv = $appenv;
    }

    /**
     * @return string
     */
    public function getAppEnv()
    {
        return $this->appenv;
    }

    /**
     * @return boolean
     */
    public function isLogging()
    {
        return $this->logging;
    }

    /**
     * @param boolean $logging
     */
    public function setLogging($logging)
    {
        $this->logging = $logging;
    }

    /**
     * @return boolean
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @param boolean $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Starts the main loop. Blocks.
     *
     * @throws \React\Socket\ConnectionException
     */
    public function run()
    {
        gc_disable(); //necessary, since connections will be dropped without reasons after several hundred connections.

        $this->loop = \React\EventLoop\Factory::create();
        $this->controller = new \React\Socket\Server($this->loop);
        $this->controller->on('connection', array($this, 'onSlaveConnection'));
        $this->controller->listen(5500);

        $this->web = new \React\Socket\Server($this->loop);
        $this->web->on('connection', array($this, 'onWeb'));
        $this->web->listen($this->port, $this->host);

        $this->tcpConnector = new \React\SocketClient\TcpConnector($this->loop);

        $pcntl = new \MKraemer\ReactPCNTL\PCNTL($this->loop);

        $pcntl->on(SIGTERM, [$this, 'signal']);
        $pcntl->on(SIGINT, [$this, 'signal']);

        $this->isRunning = true;
        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");

        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newInstance(5501 + $i);
        }

        $this->loop->run();
    }

    /**
     * Handles incoming connections from $this->port. Basically redirects to a slave.
     *
     * @param \React\Socket\Connection $incoming incoming connection from react
     */
    public function onWeb(\React\Socket\Connection $incoming)
    {
        if ($this->isDebug()) {
            $this->checkChangedFiles();
        }

        // preload sent data from $incoming to $buffer, otherwise it would be lost,
        // since getNextSlave is async.
        $redirect = null;
        $buffer = '';
        $incoming->on(
            'data',
            function ($data) use (&$redirect, &$buffer) {
                if (!$redirect) {
                    $buffer .= $data;
                }
            }
        );

        $this->getNextSlave(
            function ($id) use ($incoming, &$buffer, &$redirect) {

                $slave =& $this->slaves[$id];
                $slave['busy'] = true;
                $slave['connections']++;

                $this->tcpConnector->create('127.0.0.1', $slave['port'])->then(
                    function (\React\Stream\Stream $stream) use (&$buffer, $redirect, $incoming, &$slave) {
                        $stream->write($buffer);

                        $stream->on(
                            'close',
                            function () use ($incoming, &$slave) {
                                $slave['busy'] = false;
                                $slave['connections']--;
                                $slave['requests']++;
                                $incoming->end();

                                if ($slave['requests'] > $this->maxRequests) {
                                    $info['ready'] = false;
                                    $slave['connection']->close();
                                }

                                if ($slave['closeWhenFree']) {
                                    $slave['connection']->close();
                                }
                            }
                        );

                        $stream->on(
                            'data',
                            function ($data) use ($incoming) {
                                $incoming->write($data);
                            }
                        );

                        $incoming->on(
                            'data',
                            function ($data) use ($stream) {
                                $stream->write($data);
                            }
                        );

                        $incoming->on(
                            'close',
                            function () use ($stream) {
                                $stream->close();
                            }
                        );
                    }
                );
            }
        );
    }

    /**
     * Returns the next free slave. This method is async, so be aware of async calls between this call.
     *
     * @return integer
     */
    protected function getNextSlave($cb)
    {
        $that = $this;
        $checkSlave = function () use ($cb, $that, &$checkSlave) {
            $minConnections = null;
            $minPort = null;

            foreach ($this->slaves as $slave) {
                if (!$slave['ready']) {
                    continue;
                }

                if (!$this->concurrentRequestsPerWorker && $slave['busy']) {
                    //we skip workers that are busy, means worker that are currently handle a connection
                    //this makes it more robust since most applications are not made to handle
                    //several request at the same time - even when one request is streaming. Would lead
                    //to strange effects&crashes in high traffic sites if not considered.
                    //maybe in the future this can be set application specific.
                    //Rule of thumb: The application may not operate on globals, statics or same file paths to get this working.
                    continue;
                }

                // we pick a slave that currently handles the fewest connections
                if (null === $minConnections || $slave['connections'] < $minConnections) {
                    $minConnections = $slave['connections'];
                    $minPort = $slave['port'];
                }
            }

            if (null !== $minPort) {
                $cb($minPort);

                return;
            }
            $this->loop->futureTick($checkSlave);
        };

        $checkSlave();
    }

    /**
     * Handles data communication from slave -> master
     *
     * @param \React\Socket\Connection $conn
     */
    public function onSlaveConnection(\React\Socket\Connection $conn)
    {
        $buffer = '';

        $conn->on(
            'data',
            \Closure::bind(
                function ($data) use ($conn, &$buffer) {
                    $buffer .= $data;

                    if (substr($buffer, -1) === PHP_EOL) {
                        foreach (explode(PHP_EOL, $buffer) as $message) {
                            if ($message) {
                                $this->processMessage($message, $conn);
                            }
                        }

                        $buffer = '';
                    }
                },
                $this
            )
        );

        $conn->on(
            'close',
            \Closure::bind(
                function () use ($conn) {
                    foreach ($this->slaves as $id => $slave) {
                        if ($slave['connection'] === $conn) {

                            if ($this->output->isVerbose()) {
                                $this->output->writeln('Worker closed '.$slave['port']);
                            }

                            $slave['ready'] = false;
                            $slave['stdout']->close();
                            $slave['stderr']->close();

                            if (is_resource($slave['process'])) {
                                proc_terminate($slave['process'], SIGKILL);
                            }

                            posix_kill($slave['pid'], SIGKILL); //make sure its really dead
                            $this->newInstance($slave['port']);

                            return;
                        }
                    }
                },
                $this
            )
        );
    }

    /**
     * A slave sent a message. Redirects to the appropriate `command*` method.
     *
     * @param array                    $data
     * @param \React\Socket\Connection $conn
     *
     * @throws \Exception when invalid 'cmd' in $data.
     */
    public function processMessage($data, \React\Socket\Connection $conn)
    {
        $array = json_decode($data, true);

        $method = 'command' . ucfirst($array['cmd']);
        if (is_callable(array($this, $method))) {
            $this->$method($array, $conn);
        } else {
            echo($data);
            throw new \Exception(sprintf('Command %s not found', $method));
        }
    }

    /**
     * A slave sent a `status` command.
     *
     * @param array                    $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandStatus(array $data, \React\Socket\Connection $conn)
    {
        $conn->end(json_encode('todo'));
    }

    /**
     * A slave sent a `register` command.
     *
     * @param array                    $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandRegister(array $data, \React\Socket\Connection $conn)
    {
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];

        if (!isset($this->slaves[$port]) || !$this->slaves[$port]['waitForRegister']) {
            throw new \LogicException('A slaves wanted to register on master which was not expected. Emergency close. port='.$port);
        }

        if ($this->output->isVerbose()) {
            $this->output->writeln('Worker registered '.$port);
        }

        $this->slaves[$port]['pid'] = $pid;
        $this->slaves[$port]['connection'] = $conn;
        $this->slaves[$port]['ready'] = true;
        $this->slaves[$port]['waitForRegister'] = false;

        if ($this->waitForSlaves && $this->slaveCount === count($this->slaves)) {
            $this->waitForSlaves = false; // all slaves started

            $this->output->writeln(
                sprintf(
                    "%d slaves (starting at 5501) up and ready. Application is ready at http://%s:%s/",
                    $this->slaveCount,
                    $this->host,
                    $this->port
                )
            );
        }
    }

    /**
     * Prints logs.
     *
     * @Todo, integrate Monolog.
     *
     * @param array                    $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandLog(array $data, \React\Socket\Connection $conn)
    {
        $this->output->writeln($data['message']);
    }

    /**
     * @param array                    $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandFiles(array $data, \React\Socket\Connection $conn)
    {
        $this->filesToTrack = array_unique(array_merge($this->filesToTrack, $data['files']));
    }

    /**
     * Checks if tracked files have changed. If so, restart all slaves.
     *
     * This approach uses simple filemtime to check against modificiation. It is using this technique because
     * all other file watching stuff have either big dependencies or do not work under all platforms without
     * installing a pecl extension. Also this way is interestingly fast and is only used when debug=true.
     */
    protected function checkChangedFiles()
    {
        $reload = false;
        $filePath = '';
        $start = microtime(true);

        foreach ($this->filesToTrack as $filePath) {
            $currentFileMTime = filemtime($filePath);
            if (isset($this->filesLastMTime[$filePath])) {
                if ($this->filesLastMTime[$filePath] !== $currentFileMTime) {
                    $this->filesLastMTime[$filePath] = $currentFileMTime;
                    $reload = true;
                    break;
                }
            } else {
                $this->filesLastMTime[$filePath] = $currentFileMTime;
            }
        }

        if ($reload) {
            $this->output->writeln(
                sprintf(
                    "<info>[%s] File changed %s (detection %f, %d). Reload workers.</info>",
                    date('d/M/Y:H:i:s O'),
                    $filePath,
                    microtime(true) - $start,
                    count($this->filesToTrack)
                )
            );

            $this->reload();
        }
    }

    /**
     * Closed all salves, so we automatically reconnect. Necessary when files have changed.
     */
    protected function reload()
    {
        $this->inReload = true;

        foreach ($this->slaves as $pid => $info) {
            $info['ready'] = false; //does not accept new connections

            if ($info['busy']) {
                $info['closeWhenFree'] = true;
            } else {
                $info['connection']->close();
            }
        };

        $this->inReload = false;
    }

    /**
     * Creates a new ProcessSlave instance and forks the process.
     *
     * @param integer $port
     */
    function newInstance($port)
    {
        $dir = var_export(__DIR__, true);

        $this->slaves[$port] = [
            'ready' => false,
            'pid' => null,
            'port' => $port,
            'closeWhenFree' => false,
            'waitForRegister' => true,
            'busy' => false,
            'requests' => 0,
            'connections' => 0,
            'connection' => null,
        ];

        if ($this->output->isVerbose()) {
            $this->output->writeln('Start new worker '.$port);
        }

        $bridge = var_export($this->getBridge(), true);
        $bootstrap = var_export($this->getAppBootstrap(), true);
        $config = [
            'port' => $port,
            'app-env' => $this->getAppEnv(),
            'debug' => $this->isDebug(),
            'logging' => $this->isLogging(),
            'static' => true
        ];

        $config = var_export($config, true);

        $script = <<<EOF
<?php

require_once file_exists($dir . '/vendor/autoload.php')
    ? $dir . '/vendor/autoload.php'
    : $dir . '/../../autoload.php';

new \PHPPM\ProcessSlave($bridge, $bootstrap, $config);
EOF;

        if ($this->phpCgiExecutable) {
          $commandline = $this->phpCgiExecutable;
        } else {
          $executableFinder = new PhpExecutableFinder();
          $commandline = $executableFinder->find() . '-cgi';
        }

        $file = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($file, $script);
        register_shutdown_function('unlink', $file);

        //we can not use -q since this disables basically all header support
        //but since this is necessary at least in symfony.
        //e.g. headers_sent() returns always true, although wrong.
        $commandline .= ' -C ' . ProcessUtils::escapeArgument($file);

        $descriptorspec = [
            ['pipe', 'r'], //stdin
            ['pipe', 'w'], //stdout
            ['pipe', 'w'], //stderr
        ];

        $this->slaves[$port]['process'] = proc_open($commandline, $descriptorspec, $pipes);

        $this->slaves[$port]['stdout'] = new \React\Stream\Stream($pipes[1], $this->loop);
        $this->slaves[$port]['stderr'] = new \React\Stream\Stream($pipes[2], $this->loop);

        $this->slaves[$port]['stdout']->on(
            'data',
            function ($data) {
                $this->output->write($data);
            }
        );
        $this->slaves[$port]['stderr']->on(
            'data',
            function ($data) {
                $this->output->write("<error>$data</error>");
            }
        );
    }
}
