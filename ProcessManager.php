<?php
declare(ticks = 1);

namespace PHPPM;

use React\Socket\Connection;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Process\ProcessUtils;

class ProcessManager
{
    use ProcessCommunicationTrait;

    /**
     * @var array
     */
    protected $slaves = [];

    /**
     * $object_hash => port
     *
     * @var string[]
     */
    protected $ports = [];

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var React\Server
     */
    protected $controller;

    /**
     * @var string
     */
    protected $controllerHost;

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
     * @var bool
     */
    protected $servingStatic = true;

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
     * If a worker is allowed to handle more than one request at the same time.
     * This can lead to issues when the application does not support it
     * (like when they operate on globals at the same time)
     *
     * @var bool
     */
    protected $concurrentRequestsPerWorker = false;

    /**
     * @var null|int
     */
    protected $lastWorkerErrorPrintBy;

    protected $filesToTrack = [];
    protected $filesLastMTime = [];
    protected $filesLastMd5 = [];

    /**
     * Counter of handled clients.
     *
     * @var int
     */
    protected $handledRequests = 0;

    /**
     * How many requests each worker is allowed to handle until it will be restarted.
     *
     * @var int
     */
    protected $maxRequests = 2000;

    /**
     * Timeout in seconds for master to worker connection.
     *
     * @var int
     */
    protected $timeout = 30;

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
    public function isServingStatic()
    {
        return $this->servingStatic;
    }

    /**
     * @param boolean $servingStatic
     */
    public function setServingStatic($servingStatic)
    {
        $this->servingStatic = $servingStatic;
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

        $this->loop = \React\EventLoop\Factory::create();
        $this->controller = new React\Server($this->loop);
        $this->controller->on('connection', array($this, 'onSlaveConnection'));

        $this->controllerHost = $this->getNewControllerHost();
        $this->controller->listen(self::CONTROLLER_PORT, $this->controllerHost);

        $this->web = new \React\Socket\Server(sprintf('%s:%d', $this->host, $this->port), $this->loop);
        $this->web->on('connection', array($this, 'onWeb'));

        $this->tcpConnector = new \React\SocketClient\TcpConnector($this->loop);

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

        $this->isRunning = true;
        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");

        for ($i = 0; $i < $this->slaveCount; $i++) {
            $this->newInstance((self::CONTROLLER_PORT+1) + $i);
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

    /**
     * Handles incoming connections from $this->port. Basically redirects to a slave.
     *
     * @param Connection $incoming incoming connection from react
     */
    public function onWeb(Connection $incoming)
    {
        //preload sent data from $incoming to $buffer, otherwise it would be lost,
        //since getNextSlave is async.
        $redirectionActive = false;
        $connectionOpen = true;

        $incomingBuffer = '';
        $incoming->on(
            'data',
            function ($data) use (&$redirectionActive, &$incomingBuffer) {
                if (!$redirectionActive) {
                    $incomingBuffer .= $data;
                }
            }
        );

        $redirectionTries = 0;
        $incoming->on('close', function () use (&$redirectionActive, &$redirectionTries, &$connectionOpen){
            $connectionOpen = false;
        });

        $start = microtime(true);
        $redirectionTries++;
        $redirectRequest = function ($id) use (&$redirectRequest, &$incoming, &$incomingBuffer, &$redirectionActive, $start, &$redirectionTries, &$connectionOpen) {
            if (!$connectionOpen) {
                //since the initial connection of a client and getting a free worker the client meanwhile closed the connection,
                //so stop anything here.
                return;
            }

            if (!is_resource($incoming->stream)) {
                //Firefox closes somehow a connection directly after opening, at this state we need to check
                //whether the connection is still alive, to keep going. This check prevents the server from crashing
                return;
            }

            $took = microtime(true) - $start;
            if ($this->output->isVeryVerbose() && $took > 1) {
                    $this->output->writeln(
                        sprintf('<info>took abnormal %f seconds for choosing next free worker</info>', $took)
                    );
            }

            $slave =& $this->slaves[$id];
            $slave['busy'] = true;
            $slave['connections']++;

            $start = microtime(true);
            $stream = stream_socket_client($slave['host'], $errno, $errstr, $this->timeout);
            if (!$stream || !is_resource($stream)) {
                //we failed to connect to the worker. Maybe because of timeouts or it is in a crashed state
                //and is currently dieing.
                //since we don't know whether the worker is only very busy or dieing we just
                //set it back to available worker list. If it is really dying it will be
                //removed from the available worker list by itself during connection:close event.
                $slave['busy'] = false;
                $slave['connections']--;

                if ($this->output->isVeryVerbose()) {
                    $this->output->writeln(
                        sprintf(
                            '<error>Connection to worker %d failed. Try #%d, took %fs. ' .
                            'Try increasing your timeout of %d. Error message: [%d] %s</error>',
                            $id, $redirectionTries, microtime(true) - $start, $this->timeout, $errno, $errstr
                        )
                    );
                }

                //Try next free client. It's important to do this here due to $incomingBuffer.
                $redirectionTries++;
                $this->getNextSlave($redirectRequest);
                return;
            }

            $connection = new \React\Socket\Connection($stream, $this->loop);

            $took = microtime(true) - $start;
            if ($this->output->isVeryVerbose() && $took > 1) {
                $this->output->writeln(
                    sprintf('<info>took abnormal %f seconds for connecting to :%d</info>', $took, $slave['port'])
                );
            }

            $start = microtime(true);

            $headersToReplace = ['X-PHP-PM-Remote-IP' => parse_url($incoming->getRemoteAddress(), PHP_URL_HOST)];
            $headerRedirected = false;

            if ($this->isHeaderEnd($incomingBuffer)) {
                $incomingBuffer = $this->replaceHeader($incomingBuffer, $headersToReplace);
                $headerRedirected = true;
                $connection->write($incomingBuffer);
            }

            $redirectionActive = true;

            $connection->on(
                'close',
                function () use ($incoming, &$slave, $start) {

                    $took = microtime(true) - $start;
                    if ($this->output->isVeryVerbose() && $took > 1) {
                        $this->output->writeln(
                            sprintf('<info>took abnormal %f seconds for handling a connection</info>', $took)
                        );
                    }

                    $slave['busy'] = false;
                    $slave['connections']--;
                    $slave['requests']++;
                    $incoming->end();

                    /** @var Connection $connection */
                    $connection = $slave['connection'];

                    if ($slave['requests'] >= $this->maxRequests) {
                        $slave['ready'] = false;
                        $this->output->writeln(sprintf('Restart worker #%d because it reached maxRequests of %d', $slave['port'], $this->maxRequests));
                        $connection->close();
                    } else if ($slave['closeWhenFree']) {
                        $connection->close();
                    }
                }
            );

            $connection->on(
                'data',
                function ($buffer) use ($incoming) {
                    $incoming->write($buffer);
                }
            );

            $incoming->on(
                'data',
                function ($buffer) use ($connection, &$incomingBuffer, $headersToReplace, &$headerRedirected) {

                    if (!$headerRedirected) {
                        $incomingBuffer .= $buffer;
                        if ($this->isHeaderEnd($incomingBuffer)) {
                            $incomingBuffer = $this->replaceHeader($incomingBuffer, $headersToReplace);
                            $headerRedirected = true;
                            $connection->write($incomingBuffer);
                        } else {
                            //head has not completely received yet, wait
                        }
                    } else {
                        //incomingBuffer has already been redirected, so redirect now buffer per buffer
                        $connection->write($buffer);
                    }
                }
            );

            $incoming->on(
                'close',
                function () use ($connection) {
                    $connection->close();
                }
            );
        };

        $this->getNextSlave($redirectRequest);
    }


    /**
     * Checks whether the end of the header is in $buffer.
     *
     * @param string $buffer
     *
     * @return bool
     */
    protected function isHeaderEnd($buffer) {
        return false !== strpos($buffer, "\r\n\r\n");
    }

    /**
     * Replaces or injects header
     *
     * @param string   $header
     * @param string[] $headersToReplace
     *
     * @return string
     */
    protected function replaceHeader($header, $headersToReplace) {
        $result = $header;

        foreach ($headersToReplace as $key => $value) {
            if (false !== $headerPosition = stripos($result, $key . ':')) {
                //check how long the header is
                $length = strpos(substr($header, $headerPosition), "\r\n");
                $result = substr_replace($result, "$key: $value", $headerPosition, $length);
            } else {
                //$key is not in header yet, add it at the end
                $end = strpos($result, "\r\n\r\n");
                $result = substr_replace($result, "\r\n$key: $value", $end, 0);
            }
        }

        return $result;
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
                $this->handledRequests++;

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
     * @param Connection $conn
     */
    public function onSlaveConnection(Connection $conn)
    {
        $this->bindProcessMessage($conn);

        $conn->on(
            'close',
            \Closure::bind(
                function () use ($conn) {
                    if ($this->inShutdown) {
                        return;
                    }

                    if (!$this->isConnectionRegistered($conn)) {
                        // this connection is not registered, so it died during the ProcessSlave constructor.
                        $this->output->writeln(
                            '<error>Worker permanently closed during PHP-PM bootstrap. Not so cool. ' .
                            'Not your fault, please create a ticket at github.com/php-pm/php-pm with' .
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
                    if (isset($slave['stderr'])) {
                        $slave['stderr']->close();
                    }

                    if (is_resource($slave['process'])) {
                        proc_terminate($slave['process'], SIGKILL);
                    }

                    posix_kill($slave['pid'], SIGKILL); //make sure its really dead

                    if ($slave['duringBootstrap']) {
                        $this->bootstrapFailed($conn);
                    }

                    $this->newInstance($slave['port']);
                },
                $this
            )
        );
    }

    /**
     * A slave sent a `status` command.
     *
     * @param array      $data
     * @param Connection $conn
     */
    protected function commandStatus(array $data, Connection $conn)
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
     * @param Connection $conn
     */
    protected function commandStop(array $data, Connection $conn)
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
     * @param Connection $conn
     */
    protected function commandRegister(array $data, Connection $conn)
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
     * @param Connection $conn
     *
     * @return null|int
     */
    protected function getPort(Connection $conn)
    {
        $id = spl_object_hash($conn);

        return $this->ports[$id];
    }

    /**
     * Whether the given connection is registered.
     *
     * @param Connection $conn
     *
     * @return bool
     */
    protected function isConnectionRegistered(Connection $conn)
    {
        $id = spl_object_hash($conn);

        return isset($this->ports[$id]);
    }

    /**
     * A slave sent a `ready` commands which basically says that the slave bootstrapped successfully the
     * application and is ready to accept connections.
     *
     * @param array      $data
     * @param Connection $conn
     */
    protected function commandReady(array $data, Connection $conn)
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
     * @param Connection $conn
     */
    protected function bootstrapFailed(Connection $conn)
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
                if(!empty($slave['connection'])) {
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
     * @param Connection $conn
     */
    protected function commandLog(array $data, Connection $conn)
    {
        $this->output->writeln($data['message']);
    }

    /**
     * @param array      $data
     * @param Connection $conn
     */
    protected function commandFiles(array $data, Connection $conn)
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

            /** @var Connection $connection */
            $connection = $slave['connection'];

            if ($connection && $connection->isWritable()) {
                if ($slave['busy']) {
                    $slave['closeWhenFree'] = true;
                } else {
                    $connection->close();
                }
            } else {
                $this->newInstance($slave['port']);
            }
        };

        $this->inReload = false;
    }

    /**
     * Creates a new ProcessSlave instance.
     *
     * @param integer $port
     */
    protected function newInstance($port)
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

        $host = Utils::isWindows() ? 'tcp://127.0.0.1' : $this->getNewSlaveSocket($port);

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
            'connections' => 0,
            'connection' => null,
            'host' => $host
        ];

        $bridge = var_export($this->getBridge(), true);
        $bootstrap = var_export($this->getAppBootstrap(), true);
        $config = [
            'port' => $slave['port'],
            'host' => $slave['host'],

            'session_path' => session_save_path(),
            'controllerHost' => Utils::isWindows() ? 'tcp://127.0.0.1' : $this->controllerHost,

            'app-env' => $this->getAppEnv(),
            'debug' => $this->isDebug(),
            'logging' => $this->isLogging(),
            'static' => $this->isServingStatic(),
        ];

        $config = var_export($config, true);

        $dir = var_export(__DIR__, true);
        $script = <<<EOF
<?php

set_time_limit(0);

require_once file_exists($dir . '/vendor/autoload.php')
    ? $dir . '/vendor/autoload.php'
    : $dir . '/../../autoload.php';

require_once $dir . '/functions.php';

//global for all global functions
\PHPPM\ProcessSlave::\$slave = new \PHPPM\ProcessSlave($bridge, $bootstrap, $config);
\PHPPM\ProcessSlave::\$slave->run();
EOF;

        $commandline = $this->phpCgiExecutable;

        $file = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($file, $script);
        register_shutdown_function('unlink', $file);

        //we can not use -q since this disables basically all header support
        //but since this is necessary at least in Symfony we can not use it.
        //e.g. headers_sent() returns always true, although wrong.
        $commandline .= ' -C ' . ProcessUtils::escapeArgument($file);

        $descriptorspec = [
            ['pipe', 'r'], //stdin
            ['pipe', 'w'], //stdout
            ['pipe', 'w'], //stderr
        ];

        $this->slaves[$port] = $slave;
        $this->slaves[$port]['process'] = proc_open($commandline, $descriptorspec, $pipes);

        $stderr = new \React\Stream\Stream($pipes[2], $this->loop);
        $stderr->on(
            'data',
            function ($data) use ($port) {
                if ($this->lastWorkerErrorPrintBy !== $port) {
                    $this->output->writeln("<info>--- Worker $port stderr ---</info>");
                    $this->lastWorkerErrorPrintBy = $port;
                }
                $this->output->write("<error>$data</error>");
            }
        );
        $this->slaves[$port]['stderr'] = $stderr;
    }
}
