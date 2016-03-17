<?php
declare(ticks = 1);

namespace PHPPM;

use Symfony\Component\Process\PhpProcess;

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

    protected $filesToTrack = [];
    protected $filesLastMTime = [];

    function __construct($port = 8080, $host = '127.0.0.1', $slaveCount = 8)
    {
        $this->slaveCount = $slaveCount;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     */
    public function signal()
    {
        echo "Termination received, exiting.\n";
        $this->controller->shutdown();
        $this->web->shutdown();
        $this->loop->tick();
        $this->loop->stop();

        foreach ($this->slaves as $pid => $slave) {
            posix_kill($slave['pid'], SIGKILL);
        }
        exit;
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
        echo "Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...\n";

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

        $this->getNextSlave(function ($slaveId) use ($incoming, &$buffer, &$redirect) {

            $slave =& $this->slaves[$slaveId];
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
                            $incoming->end();
                            unset($slave['socket']);

                            if ($slave['closeWhenFree']) {
                                $slave['ready'] = false;
                                $slave['connection']->close();
                                unset($this->slaves[$slave['pid']]);
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

                });
        });
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
            $minPid = null;

            foreach ($this->slaves as $pid => $slave) {
                if (!$slave['ready']) continue;

                // we pick a slave that currently handles the fewest connections
                if (null === $minConnections || $slave['connections'] < $minConnections) {
                    $minConnections = $slave['connections'];
                    $minPid = $pid;
                }
            }

            if (null !== $minPid) {
                $cb($minPid);
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
                    foreach ($this->slaves as $pid => $slave) {
                        if ($slave['connection'] === $conn) {
                            $this->slaves[$pid]['ready'] = false;
                            posix_kill($slave['pid'], SIGKILL);
                            unset($this->slaves[$pid]);
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
     * @param array $data
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
     * @param array $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandStatus(array $data, \React\Socket\Connection $conn)
    {
        $result['activeSlaves'] = count($this->slaves);
        $conn->end(json_encode($result));
    }

    /**
     * A slave sent a `register` command.
     *
     * @param array $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandRegister(array $data, \React\Socket\Connection $conn)
    {
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];

        $this->slaves[$pid] = array(
            'pid' => $pid,
            'port' => $port,
            'closeWhenFree' => false,
            'socket' => null,
            'busy' => false,
            'ready' => true,
            'connections' => 0,
            'connection' => $conn
        );

        if ($this->waitForSlaves && $this->slaveCount === count($this->slaves)) {
            $this->waitForSlaves = false; // all slaves started

            echo sprintf(
                "%d slaves (starting at 5501) up and ready. Application is ready at http://localhost:%s/\n",
                $this->slaveCount,
                $this->port
            );
        }
    }

    /**
     * Prints logs.
     *
     * @Todo, integrate Monolog.
     *
     * @param array $data
     * @param \React\Socket\Connection $conn
     */
    protected function commandLog(array $data, \React\Socket\Connection $conn)
    {
        echo $data['message'] . PHP_EOL;
    }

    /**
     * @param array $data
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
            echo sprintf(
                "[%s] File changed %s (detection %f, %d). Reload slaves.\n",
                date('d/M/Y:H:i:s O'),
                $filePath,
                microtime(true) - $start,
                count($this->filesToTrack)
            );

            $this->reload();
        }
    }

    /**
     * Closed all salves, so we automatically reconnect. Necessary when files have changed.
     */
    protected function reload()
    {
        $this->inReload = true; //deactivate auto calling of checkSlaves when a connection to slave closes.

        foreach ($this->slaves as $pid => $info) {
            $info['ready'] = false; //does not accept new connections

            if ($info['busy']) {
                $info['closeWhenFree'] = true;
            } else {
                $info['connection']->close();
            }
        };


        $this->availableSlave = [];
        $this->slaves = [];

        $this->inReload = false;
        $this->checkSlaves();
    }

    /**
     * Checks if we have $this->slaveCount alive. If not, it starts new slaves.
     */
    protected function checkSlaves()
    {
        if (!$this->isRunning || $this->waitForSlaves || $this->inReload) {
            return;
        }

        $i = count($this->slaves);
        if ($this->slaveCount !== $i) {
//            echo sprintf("Boot %d new slaves ... \n", $this->slaveCount - $i);
            for (; $i < $this->slaveCount; $i++) {
                $this->newInstance(5501 + $i);
            }
        }
    }

    /**
     * Creates a new ProcessSlave instance and forks the process.
     * @param integer $port
     */
    function newInstance($port)
    {
        $debug = var_export($this->isDebug(), true);
        $isLogging = var_export($this->isLogging(), true);
        $static = var_export(true, true);
        $dir = var_export(__DIR__, true);

        $code = <<<EOF
<?php

include $dir . '/vendor/autoload.php';

new \PHPPM\ProcessSlave('{$this->getBridge()}', '{$this->getAppBootstrap()}', [
    'app-env' => '{$this->getAppEnv()}',
    'debug' => $debug,
    'port' => $port,
    'logging' => $isLogging,
    'static' => $static
]);

EOF;
        $process = new PhpProcess($code);
        $process->start();
    }
}
