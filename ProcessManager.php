<?php

namespace PHPPM;

use function Amp\asyncCall;
use function Amp\asyncCoroutine;
use Amp\ByteStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\Coroutine;
use Amp\Loop;
use function Amp\Promise\rethrow;
use Amp\Socket\ClientConnectContext;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use Amp\Socket\Server;
use Amp\Socket\ServerListenContext;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
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
     * @var int[]
     */
    protected $ports = [];

    /**
     * @var Server
     */
    protected $controllerSocket;

    /**
     * @var string
     */
    protected $controllerHost;

    /**
     * @var Socket
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
     * Location of the file where we're going to store the PID of the master process
     */
    protected $pidfile;

    /**
     * ProcessManager constructor.
     *
     * @param OutputInterface $output
     * @param int             $port
     * @param string          $host
     * @param int             $slaveCount
     */
    public function __construct(OutputInterface $output, int $port = 8080, string $host = '127.0.0.1', int $slaveCount = 8)
    {
        $this->output = $output;
        $this->port = $port;
        $this->host = $host;
        $this->slaveCount = $slaveCount;

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

        // this method is also called during startup when something crashed, so
        // make sure we don't operate on nulls.
        if ($this->controllerSocket) {
            $this->controllerSocket->close();
        }
        if ($this->web) {
            $this->web->close();
        }

        foreach ($this->slaves as $slave) {
            if (is_resource($slave['process'])) {
                proc_terminate($slave['process']);
            }

            if ($slave['pid']) {
                // make sure its dead
                posix_kill($slave['pid'], SIGKILL);
            }
        }

        @unlink($this->pidfile);

        exit;
    }

    /**
     * @param int $maxRequests
     */
    public function setMaxRequests(int $maxRequests)
    {
        $this->maxRequests = $maxRequests;
    }

    /**
     * @param string $phpCgiExecutable
     */
    public function setPhpCgiExecutable(string $phpCgiExecutable)
    {
        $this->phpCgiExecutable = $phpCgiExecutable;
    }

    /**
     * @param boolean $concurrentRequestsPerWorker
     */
    public function setConcurrentRequestsPerWorker(bool $concurrentRequestsPerWorker)
    {
        $this->concurrentRequestsPerWorker = $concurrentRequestsPerWorker;
    }

    /**
     * @param string $bridge
     */
    public function setBridge(string $bridge)
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
    public function setAppBootstrap(string $appBootstrap)
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
    public function setAppEnv(string $appenv = null)
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
    public function setLogging(bool $logging)
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
    public function setServingStatic(bool $servingStatic)
    {
        $this->servingStatic = $servingStatic;
    }

    public function setPIDFile(string $pidfile)
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
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
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

        $this->controllerSocket = listen('tcp://127.0.0.1:0');
        $this->controllerHost = $this->controllerSocket->getAddress();

        asyncCall(function () {
            while (null !== $client = yield $this->controllerSocket->accept()) {
                $this->onSlaveConnection($client);
            }
        });

        $this->web = listen($this->host . ":" . $this->port);

        asyncCall(function () {
            while (null !== $client = yield $this->web->accept()) {
                $this->onWeb($client);
            }
        });

        Loop::onSignal(SIGTERM, [$this, 'shutdown']);
        Loop::onSignal(SIGINT, [$this, 'shutdown']);
        Loop::onSignal(SIGCHLD, [$this, 'handleSigchld']);
        Loop::onSignal(SIGUSR1, [$this, 'restartWorker']);

        if ($this->isDebug()) {
            Loop::repeat(500, function () {
                $this->checkChangedFiles();
            });
        }

        $this->isRunning = true;
        $loopClass = (new \ReflectionClass(Loop::get()))->getShortName();

        $this->output->writeln("<info>Starting PHP-PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");
        $this->writePid();

        for ($i = 0; $i < $this->slaveCount; $i++) {
            // We bind a dynamic port per slave here, because Aerys doesn't support dynamic host ports. Note that we
            // only bind these ports, but do not listen on them, so that no clients are in the socket's accept queue.
            $context = \stream_context_create([
                'socket' => [
                    'so_reuseport' => true,
                ],
            ]);

            $uri = 'tcp://127.0.0.1:0';
            $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context);

            if (!$server || $errno) {
                throw new SocketException(\sprintf("Could not create server %s: [Error: #%d] %s", $uri, $errno, $errstr));
            }

            $socketAddr = \stream_socket_get_name($server, false);
            $socketPort = \explode(':', $socketAddr)[1];

            $this->newInstance($socketPort);
        }

        Loop::run();
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
     * @param Socket $incoming incoming connection from react
     */
    public function onWeb(Socket $incoming)
    {
        $redirectionTries = 1;
        $start = microtime(true);

        $redirectRequest = function ($id) use (&$redirectRequest, &$incoming, $start, &$redirectionTries) {
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

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln('Redirecting request to slave ' . $slave['port']);
            }

            try {
                /** @var Socket $slaveSocket */
                $slaveSocket = yield connect($slave['host'], (new ClientConnectContext())->withConnectTimeout($this->timeout));
            } catch (SocketException $e) {
                // we failed to connect to the worker. Maybe because of timeouts or it is in a crashed state
                // and is currently dieing.
                // since we don't know whether the worker is only very busy or dieing we just
                // set it back to available worker list. If it is really dying it will be
                // removed from the available worker list by itself during connection:close event.
                $slave['busy'] = false;
                $slave['connections']--;

                if ($this->output->isVeryVerbose()) {
                    $this->output->writeln(
                        sprintf(
                            '<error>Connection to worker %d failed. Try #%d, took %fs. ' .
                            'Try increasing your timeout of %d. Error message: %s</error>',
                            $id, $redirectionTries, microtime(true) - $start, $this->timeout, $e->getMessage()
                        )
                    );
                }

                // Try next free client. It's important to do this here due to $incomingBuffer.
                $redirectionTries++;
                $this->getNextSlave($redirectRequest);

                return;
            }

            $took = microtime(true) - $start;

            if ($this->output->isVeryVerbose() && $took > 1) {
                $this->output->writeln(
                    sprintf('<info>took abnormal %f seconds for connecting to :%d</info>', $took, $slave['port'])
                );
            }

            $start = microtime(true);
            $buffer = "";

            while (false === strpos($buffer, "\r\n\r\n")) {
                $chunk = yield $incoming->read();

                if ($chunk === null) { // socket closed
                    return;
                }

                $buffer .= $chunk;
            }

            $position = strpos($buffer, "\r\n\r\n");
            $head = substr($buffer, 0, $position + 4);
            $body = substr($buffer, $position + 4);

            // Rewrite port in host header to make Aerys' VHost matching happy
            $hostHeaderRegex = "(\r\n(Host): ([^:]+)(?::" . $this->port . ")?\r\n)i";
            $head = \preg_replace($hostHeaderRegex, "\r\n\\1: \\2:" . $slave['port'] . "\r\n", $head);

            $head = str_replace("\r\n\r\n", "\r\nx-php-pm-remote-ip: " . parse_url($incoming->getRemoteAddress(), PHP_URL_HOST) . "\r\n\r\n", $head);
            $buffer = $head . $body;

            yield $slaveSocket->write($buffer);

            ByteStream\pipe($incoming, $slaveSocket);

            try {
                yield ByteStream\pipe($slaveSocket, $incoming);
            } catch (ByteStream\StreamException $e) {
                // ignore, peer closed
            }

            $took = microtime(true) - $start;

            if ($this->output->isVeryVerbose() && $took > 1) {
                $this->output->writeln(
                    sprintf('<info>took abnormal %f seconds for handling a connection</info>', $took)
                );
            }

            $slave['busy'] = false;
            $slave['connections']--;
            $slave['requests']++;

            $incoming->close();

            /** @var Socket $socket */
            $socket = $slave['connection'];

            if ($slave['requests'] >= $this->maxRequests) {
                $slave['ready'] = false;
                $this->output->writeln(sprintf('Restart worker %d because it reached maxRequests of %d', $slave['port'], $this->maxRequests));
                $socket->close();
            } else if ($slave['closeWhenFree']) {
                $socket->close();
            }
        };

        $this->getNextSlave(asyncCoroutine($redirectRequest));
    }

    /**
     * Returns the next free slave. This method is async, so be aware of async calls between this call.
     *
     * @return integer
     */
    protected function getNextSlave($cb)
    {
        $checkSlave = function () use ($cb, &$checkSlave) {
            $minConnections = null;
            $minPort = null;

            foreach ($this->slaves as $slave) {
                if (!$slave['ready']) {
                    continue;
                }

                if (!$this->concurrentRequestsPerWorker && $slave['busy']) {
                    // we skip workers that are busy, means worker that are currently handle a connection
                    // this makes it more robust since most applications are not made to handle
                    // several request at the same time - even when one request is streaming. Would lead
                    // to strange effects&crashes in high traffic sites if not considered.
                    // maybe in the future this can be set application specific.
                    // Rule of thumb: The application may not operate on globals, statics or same file paths to get this working.
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

            // TODO: Replace with more efficient solution that doesn't burn the CPU
            Loop::defer($checkSlave);
        };

        $checkSlave();
    }

    /**
     * Handles data communication from slave -> master
     *
     * @param ServerSocket $socket
     */
    public function onSlaveConnection(ServerSocket $socket)
    {
        $promise = new Coroutine($this->receiveProcessMessages($socket));
        $promise->onResolve(function (\Throwable $error = null) use ($socket) {
            if ($this->inShutdown) {
                return;
            }

            if ($error) {
                $this->output->writeln('<error>' . $error->getMessage() . '</error>');
            }

            if (!$this->isConnectionRegistered($socket)) {
                // this connection is not registered, so it died during the ProcessSlave constructor.
                $this->output->writeln(
                    '<error>Worker permanently closed during PHP-PM bootstrap. Not so cool. ' .
                    'Not your fault, please create a ticket at github.com/php-pm/php-pm with ' .
                    'the output of `ppm start -vv`.</error>'
                );

                return;
            }

            $port = $this->getPort($socket);
            $slave =& $this->slaves[$port];

            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(sprintf('Worker %d closed after %d handled requests', $slave['port'], $slave['requests']));
            }

            $slave['ready'] = false;
            if (isset($slave['stderr'])) {
                $slave['stderr']->close();
            }

            if (is_resource($slave['process'])) {
                proc_terminate($slave['process'], SIGKILL);
            }

            posix_kill($slave['pid'], SIGKILL); // make sure its really dead

            if ($slave['duringBootstrap']) {
                $this->bootstrapFailed($socket);
            }

            $this->newInstance($slave['port']);
        });

        \Amp\Promise\rethrow($promise);
    }

    /**
     * A slave sent a `status` command.
     *
     * @param Socket $socket
     * @param array  $data
     */
    protected function commandStatus(Socket $socket, array $data)
    {
        $socket->end(json_encode([
            'slave_count' => $this->slaveCount,
            'handled_requests' => $this->handledRequests,
            'handled_requests_per_worker' => array_column($this->slaves, 'requests', 'port')
        ]));
    }

    /**
     * A slave sent a `stop` command.
     *
     * @param Socket $socket
     * @param array  $data
     */
    protected function commandStop(Socket $socket, array $data)
    {
        $socket->end(json_encode([]));
        $this->shutdown(true);
    }

    /**
     * A slave sent a `register` command.
     *
     * @param Socket $socket
     * @param array  $data
     */
    protected function commandRegister(Socket $socket, array $data)
    {
        $pid = (int) $data['pid'];
        $port = (int) $data['port'];

        if (!isset($this->slaves[$port]) || !$this->slaves[$port]['waitForRegister']) {
            if ($this->output->isVeryVerbose()) {
                $this->output->writeln(sprintf(
                    '<error>Worker #%d wanted to register on master which was not expected.</error>',
                    $port
                ));
            }

            $socket->close();

            return;
        }

        $this->ports[spl_object_hash($socket)] = $port;

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d registered. Waiting for application bootstrap ... ', $port));
        }

        $this->slaves[$port]['pid'] = $pid;
        $this->slaves[$port]['connection'] = $socket;
        $this->slaves[$port]['ready'] = false;
        $this->slaves[$port]['waitForRegister'] = false;
        $this->slaves[$port]['duringBootstrap'] = true;

        $this->sendMessage($socket, 'bootstrap');
    }

    /**
     * @param Socket $socket
     *
     * @return null|int
     */
    protected function getPort(Socket $socket)
    {
        return $this->ports[spl_object_hash($socket)];
    }

    /**
     * Whether the given connection is registered.
     *
     * @param Socket $socket
     *
     * @return bool
     */
    protected function isConnectionRegistered(Socket $socket): bool
    {
        return isset($this->ports[\spl_object_hash($socket)]);
    }

    /**
     * A slave sent a `ready` commands which basically says that the slave bootstrapped the application successfully and
     * is ready to accept connections.
     *
     * @param Socket $socket
     * @param array $data
     */
    protected function commandReady(Socket $socket, array $data)
    {
        $port = $this->getPort($socket);

        $this->slaves[$port]['ready'] = true;
        $this->slaves[$port]['bootstrapFailed'] = 0;
        $this->slaves[$port]['duringBootstrap'] = false;

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d ready.', $port));
        }

        $readySlaves = array_filter($this->slaves, function ($item) {
            return $item['ready'];
        });

        if (($this->emergencyMode || $this->waitForSlaves) && $this->slaveCount === count($readySlaves)) {
            if ($this->emergencyMode) {
                $this->output->writeln("<info>Emergency survived. Workers up and running again.</info>");
            } else {
                $this->output->writeln(
                    sprintf(
                        "<info>%d workers up and ready. Application is ready at http://%s:%s/</info>",
                        $this->slaveCount,
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
     * @param Socket $socket
     */
    protected function bootstrapFailed(Socket $socket)
    {
        $port = $this->getPort($socket);
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
     * @todo, integrate Monolog.
     *
     * @param Socket $socket
     * @param array  $data
     */
    protected function commandLog(Socket $socket, array $data)
    {
        $this->output->writeln($data['message']);
    }

    /**
     * @param Socket $socket
     * @param array  $data
     */
    protected function commandFiles(Socket $socket, array $data)
    {
        if (!$this->isConnectionRegistered($socket)) {
            return;
        }

        if ($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Received %d files from %d', count($data['files']), $this->getPort($socket)));
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
    protected function checkChangedFiles($restartWorkers = true): bool
    {
        if ($this->inReload) {
            return false;
        }

        \clearstatcache();

        $reload = false;
        $filePath = '';
        $start = \microtime(true);

        foreach ($this->filesToTrack as $idx => $filePath) {
            if (!\file_exists($filePath)) {
                continue;
            }

            $currentFileMTime = \filemtime($filePath);

            if (isset($this->filesLastMTime[$filePath])) {
                if ($this->filesLastMTime[$filePath] !== $currentFileMTime) {
                    $this->filesLastMTime[$filePath] = $currentFileMTime;

                    $md5 = \md5_file($filePath);
                    if (!isset($this->filesLastMd5[$filePath]) || $md5 !== $this->filesLastMd5[$filePath]) {
                        $this->filesLastMd5[$filePath] = $md5;
                        $reload = true;

                        //since chances are high that this file will change again we
                        //move this file to the beginning of the array, so next check is way faster.
                        unset($this->filesToTrack[$idx]);
                        \array_unshift($this->filesToTrack, $filePath);
                        break;
                    }
                }
            } else {
                $this->filesLastMTime[$filePath] = $currentFileMTime;
            }
        }

        if ($reload && $restartWorkers) {
            $this->output->writeln(
                \sprintf(
                    "<info>[%s] File changed %s (detection %f, %d). Reload workers.</info>",
                    \date('d/M/Y:H:i:s O'),
                    $filePath,
                    \microtime(true) - $start,
                    \count($this->filesToTrack)
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
            $slave['ready'] = false; // does not accept new connections
            $slave['keepClosed'] = false;

            // important to not get 'bootstrap failed' exception, when the bootstrap changes files.
            $slave['duringBootstrap'] = false;

            $slave['bootstrapFailed'] = 0;

            /** @var Socket $socket */
            $socket = $slave['connection'];

            if ($socket) {
                if ($slave['busy']) {
                    $slave['closeWhenFree'] = true;
                } else {
                    $socket->close();
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
     * @param int $port Port to listen on for HTTP requests.
     */
    protected function newInstance(int $port)
    {
        if ($this->inShutdown) {
            // when we are in the shutdown phase, we close all connections
            // as a result it actually tries to reconnect the slave, but we forbid it in this phase.
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
            $this->output->writeln(sprintf("Start new worker %d", $port));
        }

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
            'host' => 'tcp://127.0.0.1:' . $port
        ];

        $bridge = var_export($this->getBridge(), true);
        $bootstrap = var_export($this->getAppBootstrap(), true);
        $config = [
            'port' => $slave['port'],
            'host' => '127.0.0.1',

            'session_path' => session_save_path(),
            'controllerHost' => $this->controllerHost,

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

require_once file_exists($dir . '/../../vendor/autoload.php')
    ? $dir . '/vendor/autoload.php'
    : $dir . '/../../autoload.php';

require_once $dir . '/functions.php';

// global for all global functions
\PHPPM\ProcessSlave::\$slave = new \PHPPM\ProcessSlave($bridge, $bootstrap, $config);
\PHPPM\ProcessSlave::\$slave->run();
EOF;

        $commandline = $this->phpCgiExecutable;

        $file = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($file, $script);
        register_shutdown_function('unlink', $file);

        // we can not use -q since this disables basically all header support
        // but since this is necessary at least in Symfony we can not use it.
        // e.g. headers_sent() returns always true, although wrong.
        $commandline .= ' -C ' . ProcessUtils::escapeArgument($file);

        $descriptors = [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w'], // stderr
        ];

        $this->slaves[$port] = $slave;
        $this->slaves[$port]['process'] = proc_open($commandline, $descriptors, $pipes);

        $stderr = new ResourceInputStream($pipes[2]);

        asyncCall(function () use ($stderr, $port) {
            while (null !== $chunk = yield $stderr->read()) {
                if ($this->lastWorkerErrorPrintBy !== $port) {
                    $this->output->writeln("<info>--- Worker $port stderr ---</info>");
                    $this->lastWorkerErrorPrintBy = $port;
                }
                $this->output->write("<error>$chunk</error>");
            }
        });

        $this->slaves[$port]['stderr'] = $stderr;
    }
}
