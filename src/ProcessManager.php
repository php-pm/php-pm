<?php
declare(ticks = 1);

namespace PHPPM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\Server;
use React\Socket\UnixServer;
use React\Socket\Connection;
use React\Socket\ServerInterface;
use React\Socket\ConnectionInterface;
use React\ChildProcess\Process;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\Process\ProcessUtils;

class ProcessManager
{
    use ProcessCommunicationTrait;

    /*
     * Load balander started, waiting for slaves to come up
     */
    const STATE_STARTING = 0;

    /*
     * Slaves started and registered
     */
    const STATE_RUNNING = 1;

    /*
     * In emergency mode we need to close all workers due a fatal error
     * and wait for file changes to be able to restart workers
     */
    const STATE_EMERGENCY = 2;

    /*
     * Load balancer is in shutdown
     */
    const STATE_SHUTDOWN = 3;

    /**
     * Load balancer status
     */
    protected $status = self::STATE_STARTING;

    /**
     * @var LoopInterface|null
     */
    protected $loop;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Maximum requests per worker before it's recycled
     *
     * @var int
     */
    protected $maxRequests = 2000;

    /**
     * Maximum amount of time a request is allowed to execute before shutting down
     *
     * @var int
     */
    protected $maxExecutionTime = 30;

    /**
     * Maximum amount of memory a worker is allowed to consume before shutting down
     *
     * @var int
     */
    protected $memoryLimit = -1;

    /**
     * Worker time to live
     *
     * @var int|null
     */
    protected $ttl;

    /**
     * @var SlavePool
     */
    protected $slaves;

    /**
     * @var string
     */
    protected $controllerHost;

    /**
     * @var ServerInterface|null
     */
    protected $controller;

    /**
     * @var ServerInterface|null
     */
    protected $web;

    /**
     * @var int
     */
    protected $slaveCount;

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
     * @var bool
     */
    protected $inChangesDetectionCycle = false;

    /**
     * Whether the server is in the restart phase.
     *
     * @var bool
     */
    protected $inRestart = false;

    /**
     * The number of seconds to wait before force closing a worker during a reload.
     *
     * @var int
     */
    protected $reloadTimeout = 30;

    /**
     * Keep track of a single reload timer to prevent multiple reloads spawning several overlapping timers.
     *
     * @var TimerInterface
     */
    protected $reloadTimeoutTimer;

    /**
     * An associative (port->slave) array of slaves currently in a graceful reload phase.
     *
     * @var Slave[]
     */
    protected $slavesToReload = [];

    /**
     * Full path to the php-cgi executable. If not set, we try to determine the
     * path automatically.
     *
     * @var string
     */
    protected $phpCgiExecutable = '';

    /**
     * @var null|int
     */
    protected $lastWorkerErrorPrintBy;

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
     * php streams are limited by default to 32 concurrent TCP connections,
     * setting the same 511 default as nginx/apache and others.
     * Beware that your system somaxconn settings might be lower than this
     */
    const TCP_BACKLOG = 511;

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
        $this->host = $host;
        $this->port = $port;

        $this->slaveCount = $slaveCount;
        $this->slaves = new SlavePool(); // create early, used during shutdown

        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     *
     * @param bool $graceful If true, will wait for busy workers to finish.
     */
    public function shutdown($graceful = true)
    {
        if ($this->status === self::STATE_SHUTDOWN) {
            return;
        }

        $this->output->writeln("<info>Server is shutting down.</info>");
        $this->status = self::STATE_SHUTDOWN;

        $remainingSlaves = count($this->slaves->getByStatus(Slave::READY));

        if ($remainingSlaves === 0) {
            // if for some reason there are no workers, the close callback won't do anything, so just quit.
            $this->quit();
        } else {
            $this->closeSlaves($graceful, function ($slave) use (&$remainingSlaves) {
                $this->terminateSlave($slave);
                $remainingSlaves--;

                if ($this->output->isVeryVerbose()) {
                    $this->output->writeln(
                        sprintf(
                            'Worker #%d terminated, %d more worker(s) to close.',
                            $slave->getPort(),
                            $remainingSlaves
                        )
                    );
                }

                if ($remainingSlaves === 0) {
                    $this->quit();
                }
            });
        }
    }

    /**
     * To be called after all workers have been terminated and the event loop is no longer in use.
     */
    private function quit()
    {
        $this->output->writeln('Stopping the process manager.');

        // this method is also called during startup when something crashed, so
        // make sure we don't operate on nulls.
        if ($this->controller) {
            @$this->controller->close();
        }
        if ($this->web) {
            @$this->web->close();
        }

        if ($this->loop) {
            $this->loop->stop();
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
     * @param int $maxExecutionTime
     */
    public function setMaxExecutionTime($maxExecutionTime)
    {
        $this->maxExecutionTime = $maxExecutionTime;
    }

    /**
     * @param int $memoryLimit
     */
    public function setMemoryLimit($memoryLimit)
    {
        $this->memoryLimit = $memoryLimit;
    }

    /**
     * @param int $ttl
     */
    public function setTtl($ttl)
    {
        $this->ttl = $ttl;
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
     * @return ?string
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
     * @return int
     */
    public function getReloadTimeout()
    {
        return $this->reloadTimeout;
    }

    /**
     * @param int $reloadTimeout
     */
    public function setReloadTimeout($reloadTimeout)
    {
        $this->reloadTimeout = $reloadTimeout;
    }

    /**
     * Starts the main loop. Blocks.
     */
    public function run()
    {
        Debug::enable();

        // make whatever is necessary to disable all stuff that could buffer output
        ini_set('zlib.output_compression', 0);
        ini_set('output_buffering', 0);
        ini_set('implicit_flush', 1);
        ob_implicit_flush(1);

        $this->loop = Factory::create();
        $this->controller = new UnixServer($this->getControllerSocketPath(), $this->loop);
        $this->controller->on('connection', [$this, 'onSlaveConnection']);

        $this->web = new Server(sprintf('%s:%d', $this->host, $this->port), $this->loop, ['backlog' => self::TCP_BACKLOG]);
        $this->web->on('connection', [$this, 'onRequest']);

        $this->loop->addSignal(SIGTERM, [$this, 'shutdown']);
        $this->loop->addSignal(SIGINT, [$this, 'shutdown']);
        $this->loop->addSignal(SIGCHLD, [$this, 'handleSigchld']);
        $this->loop->addSignal(SIGUSR1, [$this, 'restartSlaves']);
        $this->loop->addSignal(SIGUSR2, [$this, 'reloadSlaves']);

        if ($this->isDebug()) {
            $this->loop->addPeriodicTimer(0.5, function () {
                $this->checkChangedFiles();
            });
        }

        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");
        $this->writePid();

        $this->createSlaves();

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
    public function onRequest(ConnectionInterface $incoming)
    {
        $this->handledRequests++;

        $handler = new RequestHandler($this->socketPath, $this->loop, $this->output, $this->slaves, $this->maxExecutionTime);
        $handler->handle($incoming);
    }

    /**
     * Handles data communication from slave -> master
     *
     * @param ConnectionInterface $connection
     */
    public function onSlaveConnection(ConnectionInterface $connection)
    {
        $this->bindProcessMessage($connection);
        $connection->on('close', function () use ($connection) {
            $this->onSlaveClosed($connection);
        });
    }

    /**
     * Handle slave closed
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function onSlaveClosed(ConnectionInterface $connection)
    {
        if ($this->status === self::STATE_SHUTDOWN) {
            return;
        }

        try {
            $slave = $this->slaves->getByConnection($connection);
        } catch (\Exception $e) {
            // this connection is not registered, so it died during the ProcessSlave constructor.
            $this->output->writeln(
                '<error>Worker permanently closed during PHP-PM bootstrap. Not so cool. ' .
                'Not your fault, please create a ticket at github.com/php-pm/php-pm with ' .
                'the output of `ppm start -vv`.</error>'
            );

            return;
        }

        // remove slave from reload killer pool
        unset($this->slavesToReload[$slave->getPort()]);

        // get status before terminating
        $status = $slave->getStatus();
        $port = $slave->getPort();

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d closed after %d handled requests', $port, $slave->getHandledRequests()));
        }

        // kill slave and remove from pool
        $this->terminateSlave($slave);

        /*
         * If slave is in registered state it died during bootstrap.
         * In this case new instances should only be created:
         * - in debug mode after file change detection via restartSlaves()
         * - in production mode immediately
         */
        if ($status === Slave::REGISTERED) {
            $this->bootstrapFailed($port);
        } else {
            // recreate
            $this->newSlaveInstance($port);
        }
    }

    /**
     * A slave sent a `status` command.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandStatus(array $data, ConnectionInterface $conn)
    {
        // remove nasty info about worker's bootstrap fail
        $conn->removeAllListeners('close');
        if ($this->output->isVeryVerbose()) {
            $conn->on('close', function () {
                $this->output->writeln('Status command requested');
            });
        }

        // create port -> requests map
        $requests = array_reduce(
            $this->slaves->getByStatus(Slave::ANY),
            function ($carry, Slave $slave) {
                $carry[$slave->getPort()] = 0 + $slave->getHandledRequests();
                return $carry;
            },
            []
        );

        switch ($this->status) {
            case self::STATE_STARTING:
                $status = 'starting';
                break;
            case self::STATE_RUNNING:
                $status = 'healthy';
                break;
            case self::STATE_EMERGENCY:
                $status = 'offline';
                break;
            default:
                $status = 'unknown';
        }

        $conn->end(json_encode([
            'status' => $status,
            'workers' => $this->slaves->getStatusSummary(),
            'handled_requests' => $this->handledRequests,
            'handled_requests_per_worker' => $requests
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

        $this->shutdown();
    }

    /**
     * A slave sent a `reload` command.
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandReload(array $data, ConnectionInterface $conn)
    {
        // remove nasty info about worker's bootstrap fail
        $conn->removeAllListeners('close');

        if ($this->output->isVeryVerbose()) {
            $conn->on('close', function () {
                $this->output->writeln('Reload command requested');
            });
        }

        $conn->end(json_encode([]));

        $this->reloadSlaves();
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

        try {
            $slave = $this->slaves->getByPort($port);
            $slave->register($pid, $conn);
        } catch (\Exception $e) {
            $this->output->writeln(sprintf(
                '<error>Worker #%d wanted to register on master which was not expected.</error>',
                $port
            ));
            $conn->close();
            return;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d registered. Waiting for application bootstrap ... ', $port));
        }

        $this->sendMessage($conn, 'bootstrap');
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
        try {
            $slave = $this->slaves->getByConnection($conn);
        } catch (\Exception $e) {
            $this->output->writeln(
                '<error>A ready command was sent by a worker with no connection. This was unexpected. ' .
                'Not your fault, please create a ticket at github.com/php-pm/php-pm with ' .
                'the output of `ppm start -vv`.</error>'
            );
            return;
        }

        $slave->ready();

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d ready.', $slave->getPort()));
        }

        if ($this->allSlavesReady()) {
            if ($this->status === self::STATE_EMERGENCY) {
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

            $this->status = self::STATE_RUNNING;
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
     * Register client files for change tracking
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandFiles(array $data, ConnectionInterface $conn)
    {
        try {
            $slave = $this->slaves->getByConnection($conn);

            $start = microtime(true);

            clearstatcache();

            $newFilesCount = 0;
            $knownFiles = array_keys($this->filesLastMTime);
            $recentlyIncludedFiles = array_diff($data['files'], $knownFiles);
            foreach ($recentlyIncludedFiles as $filePath) {
                if (file_exists($filePath)) {
                    $this->filesLastMTime[$filePath] = filemtime($filePath);
                    $this->filesLastMd5[$filePath] = md5_file($filePath, true);
                    $newFilesCount++;
                }
            }

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(
                    sprintf(
                        'Received %d new files from %d. Stats collection cycle: %u files, %.3f ms',
                        $newFilesCount,
                        $slave->getPort(),
                        count($this->filesLastMTime),
                        (microtime(true) - $start) * 1000
                    )
                );
            }
        } catch (\Exception $e) {
            // silent
        }
    }

    /**
     * Receive stats from the worker such as current memory use
     *
     * @param array      $data
     * @param ConnectionInterface $conn
     */
    protected function commandStats(array $data, ConnectionInterface $conn)
    {
        try {
            $slave = $this->slaves->getByConnection($conn);
            $slave->setUsedMemory($data['memory_usage']);
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(
                    sprintf(
                        'Current memory usage for worker %d: %.2f MB',
                        $slave->getPort(),
                        $data['memory_usage']
                    )
                );
            }
        } catch (\Exception $e) {
            // silent
        }
    }

    /**
     * Handles failed application bootstraps.
     *
     * @param int $port
     */
    protected function bootstrapFailed($port)
    {
        if ($this->isDebug()) {
            $this->output->writeln('');

            if ($this->status !== self::STATE_EMERGENCY) {
                $this->status = self::STATE_EMERGENCY;

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

            $this->reloadSlaves(false);
        } else {
            $this->output->writeln(
                sprintf(
                    '<error>Application bootstrap failed. Restarting worker #%d ...</error>',
                    $port
                )
            );

            $this->newSlaveInstance($port);
        }
    }

    /**
     * Checks if tracked files have changed. If so, restart all slaves.
     *
     * This approach uses simple filemtime to check against modifications. It is using this technique because
     * all other file watching stuff have either big dependencies or do not work under all platforms without
     * installing a pecl extension. Also this way is interestingly fast and is only used when debug=true.
     *
     * @param bool $restartSlaves
     *
     * @return bool
     */
    protected function checkChangedFiles($restartSlaves = true)
    {
        if ($this->inChangesDetectionCycle) {
            return false;
        }

        $start = microtime(true);
        $hasChanged = false;

        $this->inChangesDetectionCycle = true;

        clearstatcache();

        foreach ($this->filesLastMTime as $filePath => $knownMTime) {
            if (!file_exists($filePath)) {
                continue;
            }

            $actualFileTime = filemtime($filePath);
            $actualFileHash = md5_file($filePath);

            if ($knownMTime !== $actualFileTime && $this->filesLastMd5[$filePath] !== $actualFileHash) {

                // update tracked entry metadata
                $this->filesLastMd5[$filePath] = $actualFileHash;
                $this->filesLastMTime[$filePath] = $actualFileTime;

                $this->output->writeln(
                    sprintf("<info>[%s] File %s has changed.</info>", date('d/M/Y:H:i:s O'), $filePath)
                );
                $hasChanged = true;
                break;
            }
        }

        if ($hasChanged) {
            $this->output->writeln(
                sprintf(
                    "<info>[%s] At least one of %u known files was changed. Reloading workers.</info>",
                    date('d/M/Y:H:i:s O'),
                    count($this->filesLastMTime)
                )
            );

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(
                    sprintf("Changes detection cycle length = %.3f ms", (microtime(true) - $start) * 1000)
                );
            }

            if ($restartSlaves) {
                $this->restartSlaves();
            }
        }

        $this->inChangesDetectionCycle = false;

        return $hasChanged;
    }

    /**
     * Populate slave pool
     *
     * @return void
     */
    public function createSlaves()
    {
        for ($i = 1; $i <= $this->slaveCount; $i++) {
            $this->newSlaveInstance(self::CONTROLLER_PORT + $i);
        }
    }

    /**
     * Close a slave
     *
     * @param Slave $slave
     *
     * @return void
     */
    protected function closeSlave($slave)
    {
        $slave->close();
        $this->slaves->remove($slave);

        if (!empty($slave->getConnection())) {
            /** @var ConnectionInterface */
            $connection = $slave->getConnection();
            $connection->removeAllListeners('close');
            $connection->close();
        }
    }

    /**
     * Reload slaves in-place, allowing busy workers to finish what they are doing.
     */
    public function reloadSlaves($graceful = true)
    {
        $this->output->writeln('<info>Reloading all workers gracefully</info>');

        $this->closeSlaves($graceful, function ($slave) {
            /** @var $slave Slave */

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(
                    sprintf(
                        'Worker #%d has been closed, reloading.',
                        $slave->getPort()
                    )
                );
            }

            $this->newSlaveInstance($slave->getPort());
        });
    }

    /**
     * Closes all slaves and fires a user-defined callback for each slave that is closed.
     *
     * If $graceful is false, slaves are closed unconditionally, regardless of their current status.
     *
     * If $graceful is true, workers that are busy are put into a locked state, and will be closed after serving the
     * current request. If a reload-timeout is configured with a non-negative value, any workers that exceed this value
     * in seconds will be killed.
     *
     * @param bool $graceful
     * @param callable $onSlaveClosed A closure that is called for each worker.
     */
    public function closeSlaves($graceful = false, $onSlaveClosed = null)
    {
        if (!$onSlaveClosed) {
            // create a default no-op if callable is undefined
            $onSlaveClosed = function ($slave) {
            };
        }

        /*
         * NB: we don't lock slave reload with a semaphore, since this could cause
         * improper reloads when long reload timeouts and multiple code edits are combined.
         */

        $this->slavesToReload = [];

        foreach ($this->slaves->getByStatus(Slave::ANY) as $slave) {
            /** @var Slave $slave */

            /*
             * Attach the callable to the connection close event, because locked workers are closed via RequestHandler.
             * For now, we still need to call onClosed() in other circumstances as ProcessManager->closeSlave() removes
             * all close handlers.
             */
            $connection = $slave->getConnection();

            if ($connection) {
                // todo: connection has to be null-checked, because of race conditions with many workers. fixed in #366
                $connection->on('close', function () use ($onSlaveClosed, $slave) {
                    $onSlaveClosed($slave);
                });
            }

            if ($graceful && $slave->getStatus() === Slave::BUSY) {
                if ($this->output->isVeryVerbose()) {
                    $this->output->writeln(sprintf('Waiting for worker #%d to finish', $slave->getPort()));
                }

                $slave->lock();
                $this->slavesToReload[$slave->getPort()] = $slave;
            } elseif ($graceful && $slave->getStatus() === Slave::LOCKED) {
                if ($this->output->isVeryVerbose()) {
                    $this->output->writeln(
                        sprintf(
                            'Still waiting for worker #%d to finish from an earlier reload',
                            $slave->getPort()
                        )
                    );
                }
                $this->slavesToReload[$slave->getPort()] = $slave;
            } else {
                $this->closeSlave($slave);
                $onSlaveClosed($slave);
            }
        }

        if ($this->reloadTimeoutTimer !== null) {
            $this->loop->cancelTimer($this->reloadTimeoutTimer);
        }

        $this->reloadTimeoutTimer = $this->loop->addTimer($this->reloadTimeout, function () use ($onSlaveClosed) {
            if ($this->slavesToReload && $this->output->isVeryVerbose()) {
                $this->output->writeln('Cleaning up workers that exceeded the graceful reload timeout.');
            }

            foreach ($this->slavesToReload as $slave) {
                $this->output->writeln(
                    sprintf(
                        '<error>Worker #%d exceeded the graceful reload timeout and was killed.</error>',
                        $slave->getPort()
                    )
                );

                $this->closeSlave($slave);
                $onSlaveClosed($slave);
            }
        });
    }

    /**
     * Restart all slaves. Necessary when watched files have changed.
     */
    public function restartSlaves()
    {
        if ($this->inRestart) {
            return;
        }

        $this->inRestart = true;

        $this->closeSlaves();
        $this->createSlaves();

        $this->inRestart = false;
    }

    /**
     * Check if all slaves have become available
     */
    protected function allSlavesReady()
    {
        if ($this->status === self::STATE_STARTING || $this->status === self::STATE_EMERGENCY) {
            $readySlaves = $this->slaves->getByStatus(Slave::READY);
            $busySlaves = $this->slaves->getByStatus(Slave::BUSY);
            return count($readySlaves) + count($busySlaves) === $this->slaveCount;
        }

        return false;
    }

    /**
     * Creates a new ProcessSlave instance.
     *
     * @param int $port
     */
    protected function newSlaveInstance($port)
    {
        if ($this->status === self::STATE_SHUTDOWN) {
            // during shutdown phase all connections are closed and as result new
            // instances are created - which is forbidden during this phase
            return;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf("Start new worker #%d", $port));
        }

        $socketpath = var_export($this->getSocketPath(), true);
        $bridge = var_export($this->getBridge(), true);
        $bootstrap = var_export($this->getAppBootstrap(), true);

        $config = [
            'port' => $port,
            'session_path' => session_save_path(),

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
    
if (!pcntl_installed()) {
    error_log(
        sprintf(
            'PCNTL is not enabled in the PHP installation at %s. See: http://php.net/manual/en/pcntl.installation.php',
            PHP_BINARY
        )
    );
    exit();
}

if (!pcntl_enabled()) {
    error_log('Some required PCNTL functions are disabled. Check `disabled_functions` in `php.ini`.');
    exit();
}

//global for all global functions
ProcessSlave::\$slave = new ProcessSlave($socketpath, $bridge, $bootstrap, $config);
ProcessSlave::\$slave->run();
EOF;

        // slave php file
        $file = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($file, $script);
        register_shutdown_function('unlink', $file);

        // we can not use -q since this disables basically all header support
        // but since this is necessary at least in Symfony we can not use it.
        // e.g. headers_sent() returns always true, although wrong.
        // For version 2.x and 3.x of \Symfony\Component\Process\Process package
        if (method_exists('\Symfony\Component\Process\ProcessUtils', 'escapeArgument')) {
            $commandline = 'exec ' . $this->phpCgiExecutable . ' -C ' . ProcessUtils::escapeArgument($file);
        } else {
            //For version 4.x of \Symfony\Component\Process\Process package
            $commandline = ['exec', $this->phpCgiExecutable, '-C', $file];
            $processInstance = new \Symfony\Component\Process\Process($commandline);
            $commandline = $processInstance->getCommandLine();
        }

        // use exec to omit wrapping shell
        $process = new Process($commandline);

        $slave = new Slave($port, $this->maxRequests, $this->memoryLimit, $this->ttl);
        $slave->attach($process);
        $this->slaves->add($slave);

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
     * @param Slave $slave
     */
    private function terminateSlave($slave)
    {
        // set closed and remove from pool
        $slave->close();

        try {
            $this->slaves->remove($slave);
        } catch (\Exception $ignored) {
        }

        /** @var Process */
        $process = $slave->getProcess();
        if ($process->isRunning()) {
            $process->terminate();
        }

        $pid = $slave->getPid();
        if (is_int($pid)) {
            posix_kill($pid, SIGKILL); // make sure it's really dead
        }
    }
}
