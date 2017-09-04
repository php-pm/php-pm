<?php

namespace PHPPM;

use Aerys\Bootable;
use Aerys\Host;
use Aerys\Middleware;
use Aerys\Request;
use Aerys\Response;
use Aerys\Root;
use Aerys\Server;
use Aerys\ServerObserver;
use function Amp\asyncCall;
use function Amp\call;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use Amp\Socket\Socket;
use Amp\Success;
use Amp\Uri\Uri;
use PHPPM\Debug\BufferingLogger;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Debug\ErrorHandler;
use function Aerys\initServer;
use function Amp\Socket\connect;

class ProcessSlave implements Bootable, ServerObserver
{
    use ProcessCommunicationTrait;

    /**
     * Current instance, used by global functions.
     *
     * @var ProcessSlave
     */
    public static $slave;

    /**
     * The HTTP server.
     *
     * @var Server
     */
    protected $server;

    /**
     * Connection to ProcessManager, master process.
     *
     * @var ClientSocket
     */
    protected $masterSocket;

    /**
     * @var string
     */
    protected $bridgeName;

    /**
     * @var Root
     */
    protected $staticRoot;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

    /**
     * @var string[]
     */
    protected $watchedFiles = [];

    /**
     * Contains the cached version of last sent files, for performance reasons
     *
     * @var array|null
     */
    protected $lastSentFiles;

    /**
     * @var bool
     */
    protected $inShutdown = false;

    /**
     * @var BufferingLogger
     */
    protected $errorLogger;

    /**
     * Copy of $_SERVER during bootstrap.
     *
     * @var array
     */
    protected $baseServer;

    /**
     * Contains some configuration options.
     *
     * 'port' => int (server port)
     * 'appenv' => string (App environment)
     * 'static' => boolean (true) (If it should serve static files)
     * 'logging' => boolean (false) (If it should log all requests)
     * ...
     *
     * @var array
     */
    protected $config;

    private $bootstrappingDeferred;

    public function __construct(string $bridgeName = null, $appBootstrap, array $config = [])
    {
        $this->config = $config;
        $this->appBootstrap = $appBootstrap;
        $this->bridgeName = $bridgeName;
        $this->baseServer = $_SERVER;

        if ($this->config['session_path']) {
            session_save_path($this->config['session_path']);
        }

        $this->bootstrappingDeferred = new Deferred;
    }

    /**
     * @return boolean
     */
    public function isDebug()
    {
        return $this->config['debug'];
    }

    /**
     * @return boolean
     */
    public function isLogging()
    {
        return $this->config['logging'];
    }

    /**
     * Shuts down the event loop. This basically exits the process.
     */
    public function shutdown()
    {
        if ($this->inShutdown) {
            return;
        }

        if ($this->errorLogger && $logs = $this->errorLogger->cleanLogs()) {
            $messages = array_map(
                function ($item) {
                    // array($level, $message, $context);
                    $message = $item[1];
                    $context = $item[2];

                    if (isset($context['file'])) {
                        $message .= ' in ' . $context['file'] . ':' . $context['line'];
                    }

                    if (isset($context['stack'])) {
                        foreach ($context['stack'] as $idx => $stack) {
                            $message .= PHP_EOL . sprintf(
                                    "#%d: %s%s %s%s",
                                    $idx,
                                    isset($stack['class']) ? $stack['class'] . '->' : '',
                                    $stack['function'],
                                    isset($stack['file']) ? 'in' . $stack['file'] : '',
                                    isset($stack['line']) ? ':' . $stack['line'] : ''
                                );
                        }
                    }

                    return $message;
                },
                $logs
            );

            error_log(implode(PHP_EOL, $messages));
        }

        $this->inShutdown = true;

        Promise\wait($this->sendCurrentFiles());

        if ($this->masterSocket) {
            $this->masterSocket->close();
        }

        if ($this->server) {
            Promise\wait($this->server->stop());
        }

        exit;
    }

    /**
     * @return boolean
     */
    protected function isServingStatic()
    {
        return $this->config['static'];
    }

    /**
     * @return Bridges\BridgeInterface
     */
    protected function getBridge()
    {
        if (null === $this->bridge && $this->bridgeName) {
            if (true === class_exists($this->bridgeName)) {
                $bridgeClass = $this->bridgeName;
            } else {
                $bridgeClass = sprintf('PHPPM\Bridges\\%s', ucfirst($this->bridgeName));
            }

            $this->bridge = new $bridgeClass;
        }

        return $this->bridge;
    }

    /**
     * Bootstraps the actual application.
     *
     * @param string  $appBootstrap
     * @param string  $appenv
     * @param boolean $debug
     *
     * @throws \Exception
     */
    protected function bootstrap($appBootstrap, $appenv, $debug): Promise
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv, $debug);
        }

        return new Success;
    }

    /**
     * Adds a file path to the watcher list queue which will be sent
     * to the master process after each request.
     *
     * @param string $path
     */
    public function registerFile($path)
    {
        if ($this->isDebug()) {
            $this->watchedFiles[] = $path;
        }
    }

    /**
     * Sends to the master a snapshot of current known php files, so it can track those files and restart
     * slaves if necessary.
     *
     * @return Promise
     */
    protected function sendCurrentFiles(): Promise
    {
        if (!$this->isDebug()) {
            return new Success;
        }

        $files = array_merge($this->watchedFiles, get_included_files());
        $flipped = array_flip($files);

        $this->watchedFiles = [];

        // speedy way checking if two arrays are different.
        if ($this->lastSentFiles && !array_diff_key($flipped, $this->lastSentFiles)) {
            return new Success;
        }

        $this->lastSentFiles = $flipped;

        return $this->sendMessage($this->masterSocket, 'files', ['files' => $files]);
    }

    /**
     * Connects to ProcessManager, master process.
     */
    public function run()
    {
        $this->errorLogger = BufferingLogger::create();
        ErrorHandler::register(new ErrorHandler($this->errorLogger));

        Loop::run(function () {
            $this->masterSocket = yield connect($this->config['controllerHost']);

            Loop::onSignal(SIGPIPE, function () { /* do nothing, PHP's CLI version ignores SIGPIPE, but php-cgi doesn't */ });
            Loop::onSignal(SIGTERM, [$this, 'shutdown']);
            Loop::onSignal(SIGINT, [$this, 'shutdown']);
            register_shutdown_function([$this, 'shutdown']);

            (new Coroutine($this->receiveProcessMessages($this->masterSocket)))->onResolve(function () {
                $this->shutdown();
            });

            $host = (new Host)
                ->name("localhost")
                ->expose($this->config['host'], $this->config['port'])
                ->use($this)
                ->use(function (Request $request, Response $response) {
                    $this->prepareEnvironment($request);

                    return $this->handleRequest($request, $response);
                });

            if ($this->isLogging()) {
                $host->use(new RequestLogger(function (string $message) {
                    Promise\rethrow($this->sendMessage($this->masterSocket, 'log', ['message' => $message]));
                }));
            }

            $this->server = initServer(new NullLogger, [$host], [
                'maxRequestsPerConnection' => 1,
                'debug' => $this->config['debug'],
            ]);

            yield $this->sendMessage($this->masterSocket, 'register', [
                'pid' => getmypid(),
                'port' => $this->config['port']
            ]);

            yield $this->bootstrap($this->appBootstrap, $this->config['app-env'], $this->isDebug());
            yield $this->server->start();
            yield $this->sendMessage($this->masterSocket, 'ready');
        });
    }

    public function boot(Server $server, PsrLogger $logger) {
        $server->attach($this);
    }

    public function update(Server $server): Promise {
        $this->staticRoot = $this->staticRoot ?? new Root($this->getBridge()->getStaticDirectory());

        return $this->staticRoot->update($server);
    }

    public function commandBootstrap(Socket $socket, array $data)
    {
        $this->bootstrappingDeferred->resolve();
    }

    /**
     * Handle a redirected request from master.
     *
     * @param Request $request
     * @param Response $response
     *
     * @return \Generator
     */
    protected function handleRequest(Request $request, Response $response)
    {
        if ($bridge = $this->getBridge()) {
            if ($this->isServingStatic()) {
                yield call(function () use ($request, $response) {
                    return $this->serveStatic($request, $response);
                });

                if ($response->state() !== Response::NONE) {
                    return;
                }
            }

            yield call([$bridge, "onRequest"], $request, $response);
            yield $this->sendCurrentFiles();
        } else {
            $response->setStatus(404);
            $response->end('No Bridge Defined.');
        }

        if (headers_sent()) {
            // when a script sent headers the cgi process needs to die because the second request
            // trying to send headers again will fail (headers already sent fatal). It's best to not even
            // try to send headers because this break the whole approach of php-pm using php-cgi.

            error_log(
                'Headers have been sent, but not redirected to client. Force restart of a worker. ' .
                'Make sure your application does not send headers on its own.'
            );

            $this->shutdown();
        }
    }

    protected function prepareEnvironment(Request $request)
    {
        $now = \microtime(true);
        $uri = new Uri($request->getUri());

        $_SERVER = $this->baseServer;
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_TIME'] = (int) $now;
        $_SERVER['REQUEST_TIME_FLOAT'] = $now;
        $_SERVER['QUERY_STRING'] = $uri->getQuery();

        foreach ($request->getAllHeaders() as $name => $values) {
            $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));

            // Ignore already present keys, mitigates attacks like HTTPoxy.
            if (isset($_SERVER[$key])) {
                continue;
            }

            $_SERVER[$key] = \implode(",", $values);
        }

        // We receive X-PHP-PM-Remote-IP from ProcessManager.
        // This header is only used to proxy the remoteAddress from master -> slave.
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_PHP_PM_REMOTE_IP'];
        unset($_SERVER['HTTP_X_PHP_PM_REMOTE_IP']);

        $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] ?? '';
        $_SERVER['REQUEST_URI'] = $uri->getPath();
        $_SERVER['DOCUMENT_ROOT'] = $_ENV['DOCUMENT_ROOT'] ?? getcwd();
        $_SERVER['SCRIPT_NAME'] = $_ENV['SCRIPT_NAME'] ?? 'index.php';
        $_SERVER['SCRIPT_FILENAME'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $_SERVER['SCRIPT_NAME'];
    }

    /**
     * @param Request  $request
     * @param Response $response
     *
     * @return Promise|\Generator|null
     */
    protected function serveStatic(Request $request, Response $response)
    {
        $uri = new Uri($request->getUri());
        $path = $uri->getPath();

        if ($path === '/') {
            $path = '/index.html';
        }

        if (substr($path, -4) === '.php') {
            return null; // continue with other responders
        }

        return ($this->staticRoot)($request, $response);
    }
}
