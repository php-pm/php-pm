<?php
declare(ticks = 1);

namespace PHPPM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Socket\UnixServer;
use React\Socket\Connection;
use React\Socket\ServerInterface;
use React\Socket\ConnectionInterface;
use React\ChildProcess\Process;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Process\ProcessUtils;

class ProcessManager
{
    use ProcessCommunicationTrait;

    /**
     * @var LoopInterface
     */
    public $loop;

    /**
     * @var OutputInterface
     */
    public $output;

    /**
     * Maximum requests per worker before it's recycled
     *
     * @var int
     */
    public $maxRequests = 2000;

    /**
     * Timeout in seconds for master to worker connection.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * @var array
     */
    protected $slaves = [];

    /**
     * $object_hash => port
     *
     * @var int[]
     */
    protected $ports = [];

    /**
     * @var string
     */
    protected $controllerHost;

    /**
     * @var ServerInterface
     */
    protected $controller;

    /**
     * @var ServerInterface
     */
    protected $web;

    /**
     * @var int
     */
    protected $slaveCount = 1;

    /**
     * @var bool
     */
    protected $waitForSlaves = true;

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
    protected $staticDirectory = '';

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
     * Full path to the php-cgi executable. If not set, we try to determine the
     * path automatically.
     *
     * @var string
     */
    protected $phpCgiExecutable = false;

    /**
     * @var bool
     */
    protected $inShutdown = false;

    /**
     * Whether we are in the emergency mode or not. True means we need to close all workers due a fatal error
     * and waiting for file changes to be able to restart workers.
     *
     * @var bool
     */
    protected $emergencyMode = false;

    /**
     * @var null|int
     */
    protected $lastWorkerErrorPrintBy;

    protected $filesToTrack = [];
    protected $filesLastMTime = [];
    protected $filesLastMd5 = [];

    /**
     * Counter of handled clients
     *
     * @var int
     */
    protected $handledRequests = 0;

    /**
     * Flag controlling populating $_SERVER var for older applications (not using full request-response flow)
     *
     * @var bool
     */
    protected $populateServer = true;

    /**
     * Location of the file where we're going to store the PID of the master process
     */
    protected $pidfile;

    /**
     * Controller port
     */
    const CONTROLLER_PORT = 5500;

    /**
     * ProcessManager constructor.
     *
     * @param OutputInterface $output
     * @param int             $port
     * @param string          $host
     * @param int             $slaveCount
     */
    public function __construct(OutputInterface $output, $port = 8080, $host = '127.0.0.1', $slaveCount = 8)
    {
        $this->output = $output;
        $this->slaveCount = $slaveCount;
        $this->host = $host;
        $this->port = $port;
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     */
    public function shutdown($graceful = false)
    {
        if ($this->inShutdown) {
            return;
        }

        $this->inShutdown = true;

        $this->output->writeln($graceful
            ? '<info>Shutdown received, exiting.</info>'
            : '<error>Termination received, exiting.</error>'
        );

        //this method is also called during startup when something crashed, so
        //make sure we don't operate on nulls.
        if ($this->controller) {
            @$this->controller->close();
        }
        if ($this->web) {
            @$this->web->close();
        }
        if ($this->loop) {
            $this->loop->tick();
            $this->loop->stop();
        }

        foreach ($this->slaves as $slave) {
            $this->terminateSlave($slave);
        }

        unlink($this->pidfile);
        exit;
    }

    /**
     * @param bool $populateServer
     */
    public function setPopulateServer($populateServer)
    {
        $this->populateServer = $populateServer;
    }

    /**
     * @return bool
     */
    public function isPopulateServer()
    {
        return $this->populateServer;
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
     * @return string
     */
    public function getStaticDirectory()
    {
        return $this->staticDirectory;
    }

    /**
     * @param string $staticDirectory
     */
    public function setStaticDirectory($staticDirectory)
    {
        $this->staticDirectory = $staticDirectory;
    }

    public function setPIDFile($pidfile)
    {
        $this->pidfile = $pidfile;
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
     */
    public function run()
    {
        Debug::enable();

        //make whatever is necessary to disable all stuff that could buffer output
        ini_set('zlib.output_compression', 0);
        ini_set('output_buffering', 0);
        ini_set('implicit_flush', 1);
        ob_implicit_flush(1);

        $this->loop = Factory::create();
        $this->controllerHost = $this->getControllerSocketPath();
        $this->controller = new UnixServer($this->controllerHost, $this->loop);
        $this->controller->on('connection', array($this, 'onSlaveConnection'));

        $this->web = new Server(sprintf('%s:%d', $this->host, $this->port), $this->loop);
        $this->web->on('connection', array($this, 'onWeb'));

        $pcntl = new \MKraemer\ReactPCNTL\PCNTL($this->loop);
        $pcntl->on(SIGTERM, [$this, 'shutdown']);
        $pcntl->on(SIGINT, [$this, 'shutdown']);
        $pcntl->on(SIGCHLD, [$this, 'handleSigchld']);
        $pcntl->on(SIGUSR1, [$this, 'restartWorker']);

        if ($this->isDebug()) {
            $this->loop->addPeriodicTimer(0.5, function () {
                $this->checkChangedFiles();
            });
        }

        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");
        $this->writePid();
        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newSlaveInstance((self::CONTROLLER_PORT+1) + $i);
        }

        $this->loop->run();
    }

    /**
     * Handling zombie processes on SIGCHLD
     */
    public function handleSigchld()
    {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
    }

    public function writePid()
    {
        $pid = getmypid();
        file_put_contents($this->pidfile, $pid);
    }

    /**
     * Handles incoming connections from $this->port. Basically redirects to a slave.
     *
     * @param Connection $incoming incoming connection from react
     */
    public function onWeb(ConnectionInterface $incoming)
    {
        $this->handledRequests++;

        $handler = new RequestHandler($this);
        $handler->handle($incoming);
    }

    /**
     * Get available slave
     *
     * @return null|array slave instance returned by reference
     */
    public function &getNextSlave()
    {
        $available = null;

        foreach ($this->slaves as &$slave) {
            // return only available workers not currently handling a connection
            if ($slave['ready'] && !$slave['busy']) {
                $available =& $slave;
                break;
            }
        }

        return $available;
    }

    /**
     * Handles data communication from slave -> master
     *
     * @param ConnectionInterface $conn
     */
    public function onSlaveConnection(ConnectionInterface $conn)
    {
        $this->bindProcessMessage($conn);

        $conn->on('close', function () use ($conn) {
            if ($this->inShutdown) {
                return;
            }

            if (!$this->isConnectionRegistered($conn)) {
                // this connection is not registered, so it died during the ProcessSlave constructor.
                $this->output->writeln(
                    '<error>Worker permanently closed during PHP-PM bootstrap. Not so cool. ' .
                    'Not your fault, please create a ticket at github.com/php-pm/php-pm with ' .
                    'the output of `ppm start -vv`.</error>'
                );

                return;
            }

            $port = $this->getPort($conn);
            $slave =& $this->slaves[$port];

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(sprintf('Worker #%d closed after %d handled requests', $slave['port'], $slave['requests']));
            }

            $slave['ready'] = false;
            $this->terminateSlave($slave);

            if ($slave['duringBootstrap']) {
                $this->bootstrapFailed($conn);
            }

            $this->newSlaveInstance($slave['port']);
        });
    }

    /**
     * A slave sent a `status` command.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandStatus(array $data, ConnectionInterface $conn)
    {
        //remove nasty info about worker's bootstrap fail
        $conn->removeAllListeners('close');
        if ($this->output->isVeryVerbose()) {
            $conn->on('close', function () {
                $this->output->writeln('Status command requested');
            });
        }
        $conn->end(json_encode([
            'slave_count' => $this->slaveCount,
            'handled_requests' => $this->handledRequests,
            'handled_requests_per_worker' => array_column($this->slaves, 'requests', 'port')
        ]));
    }

    /**
     * A slave sent a `stop` command.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandStop(array $data, ConnectionInterface $conn)
    {
        if ($this->output->isVeryVerbose()) {
            $conn->on('close', function () {
                $this->output->writeln('Stop command requested');
            });
        }

        $conn->end(json_encode([]));

        $this->shutdown(true);
    }

    /**
     * A slave sent a `register` command.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandRegister(array $data, ConnectionInterface $conn)
    {
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];

        if (!isset($this->slaves[$port]) || !$this->slaves[$port]['waitForRegister']) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(sprintf(
                    '<error>Worker #%d wanted to register on master which was not expected.</error>',
                    $port));
            }
            $conn->close();
            return;
        }

        $this->ports[spl_object_hash($conn)] = $port;

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d registered. Waiting for application bootstrap ... ', $port));
        }

        $this->slaves[$port]['pid'] = $pid;
        $this->slaves[$port]['connection'] = $conn;
        $this->slaves[$port]['ready'] = false;
        $this->slaves[$port]['waitForRegister'] = false;
        $this->slaves[$port]['duringBootstrap'] = true;

        $this->sendMessage($conn, 'bootstrap');
    }

    /**
     * @param ConnectionInterface $conn
     *
     * @return null|int
     */
    protected function getPort(ConnectionInterface $conn)
    {
        $id = spl_object_hash($conn);

        return $this->ports[$id];
    }

    /**
     * Whether the given connection is registered.
     *
     * @param ConnectionInterface $conn
     *
     * @return bool
     */
    protected function isConnectionRegistered(ConnectionInterface $conn)
    {
        $id = spl_object_hash($conn);

        return isset($this->ports[$id]);
    }

    /**
     * A slave sent a `ready` commands which basically says that the slave bootstrapped successfully the
     * application and is ready to accept connections.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandReady(array $data, ConnectionInterface $conn)
    {
        $port = $this->getPort($conn);
        $this->slaves[$port]['ready'] = true;
        $this->slaves[$port]['bootstrapFailed'] = 0;
        $this->slaves[$port]['duringBootstrap'] = false;

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d ready.', $port));
        }

        $readySlaves = array_filter($this->slaves, function($item){
            return $item['ready'];
        });

        if (($this->emergencyMode || $this->waitForSlaves) && $this->slaveCount === count($readySlaves)) {
            if ($this->emergencyMode) {
                $this->output->writeln("<info>Emergency survived. Workers up and running again.</info>");
            } else {
                $this->output->writeln(
                    sprintf(
                        "<info>%d workers (starting at %d) up and ready. Application is ready at http://%s:%s/</info>",
                        $this->slaveCount,
                        self::CONTROLLER_PORT+1,
                        $this->host,
                        $this->port
                    )
                );
            }

            $this->waitForSlaves = false; // all slaves started
            $this->emergencyMode = false; // emergency survived
        }
    }

    /**
     * Handles failed application bootstraps.
     *
     * @param ConnectionInterface $conn
     */
    protected function bootstrapFailed(ConnectionInterface $conn)
    {
        $port = $this->getPort($conn);
        $this->slaves[$port]['bootstrapFailed']++;

        if ($this->isDebug()) {

            $this->output->writeln('');

            if (!$this->emergencyMode) {
                $this->emergencyMode = true;
                $this->output->writeln(
                    sprintf(
                        '<error>Application bootstrap failed. We are entering emergency mode now. All offline. ' .
                        'Waiting for file changes ...</error>'
                    )
                );
            } else {
                $this->output->writeln(
                    sprintf(
                        '<error>Application bootstrap failed. We are still in emergency mode. All offline. ' .
                        'Waiting for file changes ...</error>'
                    )
                );
            }

            foreach ($this->slaves as &$slave) {
                $slave['keepClosed'] = true;
                if (!empty($slave['connection'])) {
                    $slave['connection']->close();
                }
            }
        } else {
            $this->output->writeln(sprintf('<error>Application bootstrap failed. Restart worker ...</error>'));
        }
    }

    /**
     * Prints logs.
     *
     * @Todo, integrate Monolog.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandLog(array $data, ConnectionInterface $conn)
    {
        $this->output->writeln($data['message']);
    }

    /**
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandFiles(array $data, ConnectionInterface $conn)
    {
        if (!$this->isConnectionRegistered($conn)) {
            return;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Received %d files from %d', count($data['files']), $this->getPort($conn)));
        }
        $this->filesToTrack = array_unique(array_merge($this->filesToTrack, $data['files']));
    }

    /**
     * Checks if tracked files have changed. If so, restart all slaves.
     *
     * This approach uses simple filemtime to check against modifications. It is using this technique because
     * all other file watching stuff have either big dependencies or do not work under all platforms without
     * installing a pecl extension. Also this way is interestingly fast and is only used when debug=true.
     *
     * @param bool $restartWorkers
     *
     * @return bool
     */
    protected function checkChangedFiles($restartWorkers = true)
    {
        if ($this->inReload) {
            return false;
        }

        clearstatcache();

        $reload = false;
        $filePath = '';
        $start = microtime(true);

        foreach ($this->filesToTrack as $idx => $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $currentFileMTime = filemtime($filePath);

            if (isset($this->filesLastMTime[$filePath])) {
                if ($this->filesLastMTime[$filePath] !== $currentFileMTime) {
                    $this->filesLastMTime[$filePath] = $currentFileMTime;

                    $md5 = md5_file($filePath);
                    if (!isset($this->filesLastMd5[$filePath]) || $md5 !== $this->filesLastMd5[$filePath]) {
                        $this->filesLastMd5[$filePath] = $md5;
                        $reload = true;

                        //since chances are high that this file will change again we
                        //move this file to the beginning of the array, so next check is way faster.
                        unset($this->filesToTrack[$idx]);
                        array_unshift($this->filesToTrack, $filePath);
                        break;
                    }
                }
            } else {
                $this->filesLastMTime[$filePath] = $currentFileMTime;
            }
        }

        if ($reload && $restartWorkers) {
            $this->output->writeln(
                sprintf(
                    "<info>[%s] File changed %s (detection %f, %d). Reload workers.</info>",
                    date('d/M/Y:H:i:s O'),
                    $filePath,
                    microtime(true) - $start,
                    count($this->filesToTrack)
                )
            );

            $this->restartWorker();
        }

        return $reload;
    }

    /**
     * Closes all slaves, so we automatically reconnect. Necessary when watched files have changed.
     */
    public function restartWorker()
    {
        if ($this->inReload) {
            return;
        }

        $this->inReload = true;

        $this->output->writeln('Restart all worker');

        foreach ($this->slaves as &$slave) {
            $slave['ready'] = false; //does not accept new connections
            $slave['keepClosed'] = false;

            //important to not get 'bootstrap failed' exception, when the bootstrap changes files.
            $slave['duringBootstrap'] = false;

            $slave['bootstrapFailed'] = 0;

            /** @var ConnectionInterface $connection */
            $connection = $slave['connection'];

            if ($connection && $connection->isWritable()) {
                if ($slave['busy']) {
                    $slave['closeWhenFree'] = true;
                } else {
                    $connection->close();
                }
            } else {
                $this->newSlaveInstance($slave['port']);
            }
        };

        $this->inReload = false;
    }

    /**
     * Creates a new ProcessSlave instance.
     *
     * @param integer $port
     */
    protected function newSlaveInstance($port)
    {
        if ($this->inShutdown) {
            //when we are in the shutdown phase, we close all connections
            //as a result it actually tries to reconnect the slave, but we forbid it in this phase.
            return;
        }

        $keepClosed = false;
        $bootstrapFailed = 0;

        if (isset($this->slaves[$port])) {
            $bootstrapFailed = $this->slaves[$port]['bootstrapFailed'];
            $keepClosed = $this->slaves[$port]['keepClosed'];

            if ($keepClosed) {
                return;
            }
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf("Start new worker #%d", $port));
        }

        $host = $this->getSlaveSocketPath($port);

        $slave = [
            'ready' => false,
            'pid' => null,
            'port' => $port,
            'closeWhenFree' => false,
            'waitForRegister' => true,

            'duringBootstrap' => false,
            'bootstrapFailed' => $bootstrapFailed,
            'keepClosed' => $keepClosed,

            'busy' => false,
            'requests' => 0,
            'connection' => null,
            'host' => $host
        ];

        $bridge = var_export($this->getBridge(), true);
        $bootstrap = var_export($this->getAppBootstrap(), true);
        $config = [
            'port' => $slave['port'],
            'host' => $slave['host'],

            'session_path' => session_save_path(),
            'controllerHost' => $this->controllerHost,

            'app-env' => $this->getAppEnv(),
            'debug' => $this->isDebug(),
            'logging' => $this->isLogging(),
            'static-directory' => $this->getStaticDirectory(),
            'populate-server-var' => $this->isPopulateServer()
        ];

        $config = var_export($config, true);

        $dir = var_export(__DIR__ . '/..', true);
        $script = <<<EOF
<?php

namespace PHPPM;

set_time_limit(0);

require_once file_exists($dir . '/vendor/autoload.php')
    ? $dir . '/vendor/autoload.php'
    : $dir . '/../../autoload.php';

if (!pcntl_enabled()) {
    throw new \RuntimeException('Some of required pcntl functions are disabled. Check `disable_functions` setting in `php.ini`.');
}

//global for all global functions
ProcessSlave::\$slave = new ProcessSlave($bridge, $bootstrap, $config);
ProcessSlave::\$slave->run();
EOF;

        $commandline = $this->phpCgiExecutable;

        $file = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($file, $script);
        register_shutdown_function('unlink', $file);

        //we can not use -q since this disables basically all header support
        //but since this is necessary at least in Symfony we can not use it.
        //e.g. headers_sent() returns always true, although wrong.
        $commandline .= ' -C ' . ProcessUtils::escapeArgument($file);

        $process = new Process($commandline);

        $slave['process'] = $process;
        $this->slaves[$port] = $slave;

        $process->start($this->loop);
        $process->stderr->on(
            'data',
            function ($data) use ($port) {
                if ($this->lastWorkerErrorPrintBy !== $port) {
                    $this->output->writeln("<info>--- Worker $port stderr ---</info>");
                    $this->lastWorkerErrorPrintBy = $port;
                }
                $this->output->write("<error>$data</error>");
            }
        );
    }

    /**
     * @param array $slave
     */
    private function terminateSlave($slave)
    {
        /** @var Process */
        $process = $slave['process'];
        if ($process->isRunning()) {
            $process->terminate();
        }

        $pid = $slave['pid'];
        if (is_int($pid)) {
            posix_kill($pid, SIGKILL); //make sure its really dead
        }
    }
}
