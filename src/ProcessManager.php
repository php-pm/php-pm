<?php
declare(ticks = 1);

namespace PHPPM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
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
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var SlavePool
     */
    protected $slaves;

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
     * The debug timer
     *
     * @var TimerInterface|null
     */
    protected $debugTimer;

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
     * Controller port
     */
    const CONTROLLER_PORT = 5500;

    /**
     * ProcessManager constructor.
     *
     * @param OutputInterface $output
     * @param Configuration   $config
     */
    public function __construct(OutputInterface $output, Configuration $config)
    {
        $this->output = $output;
        $this->config = $config;

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

        $remainingSlaves = count($this->slaves->getByStatus(Slave::ANY));

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
    protected function quit()
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
            $this->loop->tick();
            $this->loop->stop();
        }

        if (file_exists($this->config->getPIDFile())) {
            unlink($this->config->getPIDFile());
        }
        exit;
    }

    /**
     * Manage the hot code reload timer as per the debug setting. Should be called during a reload.
     */
    protected function updateCodeReloadTimer()
    {
        // Update the state of the debug hot code reload timer
        if ($this->config->isDebug() && !$this->debugTimer) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln('Debug mode is active: hot code reloading is now enabled.');
            }

            $this->debugTimer = $this->loop->addPeriodicTimer(0.5, function () {
                $this->checkChangedFiles();
            });
        } elseif (!$this->config->isDebug() && $this->debugTimer) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln('Debug mode is inactive: hot code reloading is now disabled.');
            }

            $this->debugTimer->cancel();
            $this->debugTimer = null;
        }
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

        $this->startListening();

        $pcntl = new \MKraemer\ReactPCNTL\PCNTL($this->loop);
        $pcntl->on(SIGTERM, [$this, 'shutdown']);
        $pcntl->on(SIGINT, [$this, 'shutdown']);
        $pcntl->on(SIGCHLD, [$this, 'handleSigchld']);
        $pcntl->on(SIGUSR1, [$this, 'restartSlaves']);
        $pcntl->on(SIGUSR2, [$this, 'reload']);

        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->config->getSlaveCount()} workers, using {$loopClass} ...</info>");
        $this->writePid();

        $this->createSlaves();

        $this->updateCodeReloadTimer();

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
        file_put_contents($this->config->getPIDFile(), $pid);
    }

    /**
     * Handles incoming connections from $this->port. Basically redirects to a slave.
     *
     * @param Connection $incoming incoming connection from react
     */
    public function onRequest(ConnectionInterface $incoming)
    {
        $this->handledRequests++;

        $handler = new RequestHandler($this->socketPath, $this->loop, $this->output, $this->slaves);
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

        $this->reload();
    }

    /**
     * Perform a reload. Reloads the configuration and performs an in-place reload of each worker.
     */
    public function reload()
    {
        $newConfig = Configuration::loadFromPath($this->config->getConfigPath());
        $newConfig->setArguments($this->config->getArguments());

        $diff = array_diff_assoc($this->config->toArray(), $newConfig->toArray());

        if ($this->output->isVeryVerbose()) {
            foreach ($diff as $key => $value) {
                $this->output->writeln(
                    sprintf(
                        "Setting %s modified: %s -> %s",
                        $key,
                        $this->config->getOption($key),
                        $newConfig->getOption($key)
                    )
                );
            }
        }

        // the following config properties will not work with a reload... yet
        $badPropKeys = ['pidfile', 'socket-path', 'host', 'port'];

        $badProps = array_filter(array_keys($diff), function ($key) use ($badPropKeys) {
            return in_array($key, $badPropKeys);
        });

        if ($badProps) {
            $this->output->writeln(
                sprintf(
                    "<error>The following immutable configuration values have changed during runtime: %s</error>",
                    implode(', ', $badProps)
                )
            );
            $this->output->writeln(
                "<error>PHP-PM will continue to run, however the previous configuration values will be used.</error>"
            );
        }

        $this->config = $newConfig;

        $this->updateCodeReloadTimer();

        // Attempt to bring manager out of emergency mode
        if ($this->status == self::STATE_EMERGENCY) {
            $this->restartSlaves();
        }

        // todo: resolve race condition with reloadSlaves/createSlaves
        $this->reloadSlaves();
        $this->createSlaves();
    }

    /**
     * Bind to the host:port configuration to accept web requests.
     *
     * @throws \RuntimeException if listener cannot be established
     */
    protected function startListening()
    {
        if ($this->web !== null) {
            @$this->web->close();
        }

        $this->web = new Server(sprintf('%s:%d', $this->config->getHost(), $this->config->getPort()), $this->loop);
        $this->web->on('connection', [$this, 'onRequest']);
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
            $this->output->writeln($e->getMessage());

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
                        $this->config->getSlaveCount(),
                        self::CONTROLLER_PORT+1,
                        $this->config->getHost(),
                        $this->config->getPort()
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
     * Handles failed application bootstraps.
     *
     * @param int $port
     */
    protected function bootstrapFailed($port)
    {
        if ($this->config->isDebug()) {
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

            $this->closeSlaves();
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

            if ($knownMTime !== filemtime($filePath) && $this->filesLastMd5[$filePath] !== md5_file($filePath, true)) {
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
     * Populate the slave pool, or remove unwanted slaves if the slave pool has more workers than is necessary.
     * This allows a reload to adjust the amount of workers.
     *
     * @return void
     */
    public function createSlaves()
    {
        $current = count($this->slaves->getByStatus(Slave::ANY));
        $diff = $this->config->getSlaveCount() - $current;

        if ($diff < 0) {
            // more workers in pool than in config -- shut down this many workers.
            $this->output->writeln(sprintf("Shutting down %d worker(s).", abs($diff)));

            for ($i = $current + $diff + 1; $i <= $current; $i++) {
                $slave = $this->slaves->getByPort(self::CONTROLLER_PORT + $i);
                $this->closeSlave($slave, true);
            }
        } elseif ($diff > 0) {
            // more workers in config than pool -- spawn this many workers.
            $this->output->writeln(sprintf("Spawning %d new worker(s).", $diff));

            for ($i = $current + 1; $i <= $diff + $current; $i++) {
                $this->newSlaveInstance(self::CONTROLLER_PORT + $i);
            }
        }
    }

    /**
     * Mark a slave as closed, and remove it from the pool.
     *
     * @param Slave $slave
     *
     * @return void
     */
    protected function removeSlave($slave)
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
     * Attempt to close a slave. Returns a bool; if true, the slave successfully closed in this step. If false,
     * graceful was set to true, and we are waiting for the slave to finish its current task.
     *
     * If graceful is true, this function should be used in conjunction with the reload timeout timer:
     * @see startReloadTimeoutTimer
     *
     * @param Slave         $slave          The slave instance
     * @param bool          $graceful       Whether we should wait for the slave to end its current task
     * @param callable|null $onSlaveClosed  The callable to be fired when the slave is closed, with the Slave as the
     *                                      argument. Defaults to a no-op
     *
     * @return bool
     */
    protected function closeSlave($slave, $graceful = false, callable $onSlaveClosed = null)
    {
        if (!$onSlaveClosed) {
            $onSlaveClosed = function ($slave) {
            };
        }

        /*
         * Attach the callable to the connection close event, because locked workers are closed via RequestHandler.
         * For now, we still need to call onSlaveClosed() in other circumstances as ProcessManager->removeSlave() removes
         * all close handlers.
         */
        $connection = $slave->getConnection();

        if ($connection) {
            $connection->on('close', function () use ($onSlaveClosed, $slave) {
                $onSlaveClosed($slave);
            });
        }

        if ($graceful && $slave->getStatus() === Slave::BUSY) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(sprintf('Waiting for worker #%d to finish', $slave->getPort()));
            }

            $slave->lock();
            return false;
        } elseif ($graceful && $slave->getStatus() === Slave::LOCKED) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(
                    sprintf(
                        'Still waiting for worker #%d to finish from an earlier reload',
                        $slave->getPort()
                    )
                );
            }
            return false;
        } else {
            $this->removeSlave($slave);
            $onSlaveClosed($slave);
            return true;
        }
    }

    /**
     * Reload slaves in-place, allowing busy workers to finish what they are doing.
     */
    public function reloadSlaves()
    {
        $this->output->writeln('<info>Reloading all workers gracefully</info>');

        $this->closeSlaves(true, function ($slave) {
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
            $closed = $this->closeSlave($slave, $graceful, $onSlaveClosed);

            if (!$closed) {
                $this->slavesToReload[$slave->getPort()] = $slave;
            }
        }

        $this->filesLastMTime = [];
        $this->filesLastMd5 = [];

        $this->startReloadTimeoutTimer($onSlaveClosed);
    }

    /**
     * Start the slave reload timeout timer. This will force close any workers that remain in the array.
     *
     * @see slavesToReload
     *
     * @param callable|null $onSlaveClosed
     */
    protected function startReloadTimeoutTimer(callable $onSlaveClosed = null)
    {
        if ($this->reloadTimeoutTimer !== null) {
            $this->reloadTimeoutTimer->cancel();
        }

        $this->reloadTimeoutTimer = $this->loop->addTimer($this->config->getReloadTimeout(), function () use ($onSlaveClosed) {
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

                $this->closeSlave($slave, false, $onSlaveClosed);
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
            return count($readySlaves) + count($busySlaves) === $this->config->getSlaveCount();
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

        $socketpath = var_export($this->config->getSocketPath(), true);
        $bridge = var_export($this->config->getBridge(), true);
        $bootstrap = var_export($this->config->getAppBootstrap(), true);

        $config = [
            'port' => $port,
            'session_path' => session_save_path(),
            'app-env' => $this->config->getAppEnv(),
            'debug' => $this->config->isDebug(),
            'logging' => $this->config->isLogging(),
            'static-directory' => $this->config->getStaticDirectory(),
            'populate-server-var' => $this->config->isPopulateServer()
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
        //For version 2.x and 3.x of \Symfony\Component\Process\Process package
        if (method_exists('\Symfony\Component\Process\ProcessUtils', 'escapeArgument')) {
            $commandline = 'exec ' . $this->config->getPhpCgiExecutable() . ' -C ' . ProcessUtils::escapeArgument($file);
        } else {
            //For version 4.x of \Symfony\Component\Process\Process package
            $commandline = ['exec', $this->config->getPhpCgiExecutable(), '-C', $file];
            $processInstance = new \Symfony\Component\Process\Process($commandline);
            $commandline = $processInstance->getCommandLine();
        }

        // use exec to omit wrapping shell
        $process = new Process($commandline);

        $slave = new Slave($port, $this->config->getMaxRequests());
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
