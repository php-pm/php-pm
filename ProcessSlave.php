<?php
declare(ticks = 1);

namespace PHPPM;

use PHPPM\Bridges\BridgeInterface;
use PHPPM\Debug\BufferingLogger;
use Evenement\EventEmitterInterface;
use MKraemer\ReactPCNTL\PCNTL;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server as HttpServer;
use React\Promise\Promise;
use React\Socket\ServerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixServer;
use React\Socket\UnixConnector;
use React\Stream\ReadableResourceStream;
use Symfony\Component\Debug\ErrorHandler;

class ProcessSlave
{
    use ProcessCommunicationTrait;

    /**
     * Current instance, used by global functions.
     *
     * @var ProcessSlave
     */
    public static $slave;

    /**
     * The HTTP Server.
     *
     * @var ServerInterface
     */
    protected $server;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * ProcessManager master process connection
     *
     * @var ConnectionInterface
     */
    protected $controller;

    /**
     * @var string
     */
    protected $bridgeName;

    /**
     * @var BridgeInterface
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
     * Real base path for static files
     *
     * @var string
     */
    protected $staticBasePath;

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

    protected $logFormat = '[$time_local] $remote_addr - $remote_user "$request" $status $bytes_sent "$http_referer"';

    /**
     * Contains some configuration options.
     *
     * 'port' => int (server port)
     * 'appenv' => string (App environment)
     * 'static-directory' => string (Static files root directory)
     * 'logging' => boolean (false) (If it should log all requests)
     * ...
     *
     * @var array
     */
    protected $config;

    public function __construct($bridgeName = null, $appBootstrap, array $config = [])
    {
        $this->config = $config;
        $this->appBootstrap = $appBootstrap;
        $this->bridgeName = $bridgeName;
        $this->baseServer = $_SERVER;

        if ($this->config['session_path']) {
            session_save_path($this->config['session_path']);
        }
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
     * @return boolean
     */
    public function isPopulateServer()
    {
        return $this->config['populate-server-var'];
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
                    //array($level, $message, $context);
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

        if ($this->loop) {
            $this->sendCurrentFiles();
            $this->loop->tick();
        }

        if ($this->controller && $this->controller->isWritable()) {
            $this->controller->close();
        }
        if ($this->server) {
            @$this->server->close();
        }
        if ($this->loop) {
            $this->sendCurrentFiles();
            $this->loop->tick();
            @$this->loop->stop();
        }

        exit;
    }

    /**
     * @return string
     */
    protected function getStaticDirectory()
    {
        return $this->config['static-directory'];
    }

    /**
     * @return BridgeInterface
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
    protected function bootstrap($appBootstrap, $appenv, $debug)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv, $debug, $this->loop);
            $this->sendMessage($this->controller, 'ready');
        }
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
     */
    protected function sendCurrentFiles()
    {
        if (!$this->isDebug()) {
            return;
        }

        $files = array_merge($this->watchedFiles, get_included_files());
        $flipped = array_flip($files);

        //speedy way checking if two arrays are different.
        if (!$this->lastSentFiles || array_diff_key($flipped, $this->lastSentFiles)) {
            $this->lastSentFiles = $flipped;
            $this->sendMessage($this->controller, 'files', ['files' => $files]);
        }

        $this->watchedFiles = [];
    }

    /**
     * Connects to ProcessManager, master process.
     */
    public function run()
    {
        $this->loop = Factory::create();

        $this->errorLogger = BufferingLogger::create();
        ErrorHandler::register(new ErrorHandler($this->errorLogger));

        $connector = new UnixConnector($this->loop);
        $connector->connect($this->config['controllerHost'])->done(
            function($controller) {
                $this->controller = $controller;

                $pcntl = new PCNTL($this->loop);
                $pcntl->on(SIGTERM, [$this, 'shutdown']);
                $pcntl->on(SIGINT, [$this, 'shutdown']);
                register_shutdown_function([$this, 'shutdown']);

                $this->bindProcessMessage($this->controller);
                $this->controller->on('close', [$this, 'shutdown']);

                // Port is the slave identifier. Since using unix sockets, host is the socket path.
                $port = $this->config['port'];
                $host = $this->config['host'];

                $this->server = new UnixServer($host, $this->loop);

                $httpServer = new HttpServer([$this, 'onRequest']);
                $httpServer->listen($this->server);

                $this->sendMessage($this->controller, 'register', ['pid' => getmypid(), 'port' => $port]);
            }
        );

        $this->loop->run();
    }

    public function commandBootstrap(array $data, ConnectionInterface $conn)
    {
        $this->bootstrap($this->appBootstrap, $this->config['app-env'], $this->isDebug());

        $this->sendCurrentFiles();
    }

    /**
     * Handles incoming requests and transforms a $request into a $response by reference.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface|Promise
     * @throws \Exception
     */
    public function onRequest(ServerRequestInterface $request)
    {
        if ($this->isPopulateServer()) {
            $this->prepareEnvironment($request);
        }

        $remoteIp = $request->getHeaderLine('X-PHP-PM-Remote-IP');
        $remotePort = $request->getHeaderLine('X-PHP-PM-Remote-Port');

        $request = $request->withoutHeader('X-PHP-PM-Remote-IP');
        $request = $request->withoutHeader('X-PHP-PM-Remote-Port');

        $request = $request->withAttribute('remote_address', $remoteIp);
        $request = $request->withAttribute('remote_port', $remotePort);

        $logTime = date('d/M/Y:H:i:s O');

        $catchLog = function ($e) {
            ppm_log((string) $e);
            return new Response(500);
        };

        try {
            $response = $this->handleRequest($request);
        } catch (\Throwable $t) {
            // PHP >= 7.0
            $response = $catchLog($t);
        } catch (\Exception $e) {
            // PHP < 7.0
            $response = $catchLog($e);
        }

        $promise = new Promise(function($resolve) use ($response) {
            return $resolve($response);
        });

        $promise = $promise->then(function(ResponseInterface $response) use ($request, $logTime, $remoteIp) {
            if ($this->isLogging()) {
                $this->logResponse($request, $response, $logTime, $remoteIp);
            }
            return $response;
        });

        return $promise;
    }

    /**
     * Handle a redirected request from master.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function handleRequest(ServerRequestInterface $request)
    {
        if ($this->getStaticDirectory()) {
            $staticResponse = $this->serveStatic($request);
            if ($staticResponse instanceof ResponseInterface) {
                return $staticResponse;
            }
        }

        if ($bridge = $this->getBridge()) {
            $response = $bridge->handle($request);
            $this->sendCurrentFiles();
        }
        else {
            $response = new Response(404, [], 'No Bridge defined');
        }

        if (headers_sent()) {
            //when a script sent headers the cgi process needs to die because the second request
            //trying to send headers again will fail (headers already sent fatal). Its best to not even
            //try to send headers because this break the whole approach of php-pm using php-cgi.
            error_log(
                'Headers have been sent, but not redirected to client. Force restart of a worker. ' .
                'Make sure your application does not send headers on its own.'
            );
            $this->shutdown();
        }
        return $response;
    }

    protected function prepareEnvironment(ServerRequestInterface $request)
    {
        $_SERVER = $this->baseServer;
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_TIME'] = (int)microtime(true);
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $_SERVER['QUERY_STRING'] = $request->getUri()->getQuery();

        foreach ($request->getHeaders() as $name => $valueArr) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $request->getHeaderLine($name);
        }

        //We receive X-PHP-PM-Remote-IP and X-PHP-PM-Remote-Port from ProcessManager.
        //This headers is only used to proxy the remoteAddress and remotePort from master -> slave.
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_PHP_PM_REMOTE_IP'];
        unset($_SERVER['HTTP_X_PHP_PM_REMOTE_IP']);
        $_SERVER['REMOTE_PORT'] = $_SERVER['HTTP_X_PHP_PM_REMOTE_PORT'];
        unset($_SERVER['HTTP_X_PHP_PM_REMOTE_PORT']);

        $_SERVER['SERVER_NAME'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $_SERVER['REQUEST_URI'] = $request->getUri()->getPath();
        $_SERVER['DOCUMENT_ROOT'] = isset($_ENV['DOCUMENT_ROOT']) ? $_ENV['DOCUMENT_ROOT'] : getcwd();
        $_SERVER['SCRIPT_NAME'] = isset($_ENV['SCRIPT_NAME']) ? $_ENV['SCRIPT_NAME'] : 'index.php';
        $_SERVER['SCRIPT_FILENAME'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $_SERVER['SCRIPT_NAME'];
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface|false returns ResponseInterface if successfully served, false otherwise
     */
    protected function serveStatic(ServerRequestInterface $request)
    {
        $path = $request->getUri()->getPath();

        if ($path === '/') {
            $path = '/index.html';
        }
        else {
            $path = str_replace("\\", '/', $path);
        }

        if (!isset($this->staticBasePath)) {
            $this->staticBasePath = realpath($this->getStaticDirectory());
        }
        $filePath = realpath($this->staticBasePath . $path);

        if (false === $filePath) {
            return false;
        }

        // prevent access outside base path
        if (strpos($filePath, $this->staticBasePath) !== 0) {
            return new Response(403);
        }

        if (substr($filePath, -4) !== '.php' && is_file($filePath)) {
            $mTime = filemtime($filePath);

            if ($request->hasHeader('If-Modified-Since')) {
                $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
                if ($ifModifiedSince && strtotime($ifModifiedSince) === $mTime) {
                    // Client's cache IS current, so we just respond '304 Not Modified'.
                    $response = new Response(304, [
                        'Last-Modified' => gmdate('D, d M Y H:i:s', $mTime) . ' GMT'
                    ]);
                    return $response;
                }
            }

            $expires = 3600; //1 h
            $response = new Response(200, [
                'Content-Type' => $this->mimeContentType($filePath),
                'Content-Length' => filesize($filePath),
                'Pragma' => 'public',
                'Cache-Control' => 'max-age=' . $expires,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mTime) . ' GMT',
                'Expires' => gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT'
            ], new ReadableResourceStream(fopen($filePath, 'r'), $this->loop));

            return $response;
        }
        return false;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $timeLocal
     * @param string $remoteIp
     */
    protected function logResponse(ServerRequestInterface $request, ResponseInterface $response, $timeLocal, $remoteIp)
    {
        $logFunction = function($size) use ($request, $response, $timeLocal, $remoteIp) {
            $requestString = $request->getMethod() . ' ' . $request->getUri()->getPath() . ' HTTP/' . $request->getProtocolVersion();
            $statusCode = $response->getStatusCode();

            if ($statusCode < 400) {
                $requestString = "<info>$requestString</info>";
                $statusCode = "<info>$statusCode</info>";
            }

            $message = str_replace([
                '$remote_addr',
                '$remote_user',
                '$time_local',
                '$request',
                '$status',
                '$bytes_sent',
                '$http_referer',
                '$http_user_agent',
            ], [
                $remoteIp,
                '-', //todo remote_user
                $timeLocal,
                $requestString,
                $statusCode,
                $size,
                $request->hasHeader('Referer') ? $request->getHeaderLine('Referer') : '-',
                $request->hasHeader('User-Agent') ? $request->getHeaderLine('User-Agent') : '-'
            ],
                $this->logFormat);

            if ($response->getStatusCode() >= 400) {
                $message = "<error>$message</error>";
            }

            $this->sendMessage($this->controller, 'log', ['message' => $message]);
        };

        if ($response->getBody() instanceof EventEmitterInterface) {
            /** @var EventEmitterInterface $body */
            $body = $response->getBody();
            $size = strlen(\RingCentral\Psr7\str($response));
            $body->on('data', function($data) use (&$size) {
                $size += strlen($data);
            });
            //using `close` event since `end` is not fired for files
            $body->on('close', function() use (&$size, $logFunction) {
                $logFunction($size);
            });
        } else {
            $logFunction(strlen(\RingCentral\Psr7\str($response)));
        }
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    protected function mimeContentType($filename)
    {
        $mimeTypes = array(
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'ts' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'swf' => 'application/x-shockwave-flash',
            'flv' => 'video/x-flv',

            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',

            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload',
            'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed',

            // audio/video
            'mp3' => 'audio/mpeg',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',

            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',

            // ms office
            'doc' => 'application/msword',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',

            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

        $ext = strtolower(substr($filename, strrpos($filename, '.') + 1));
        if (isset($mimeTypes[$ext])) {
            return $mimeTypes[$ext];
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME);

            //we need to suppress all stuff of this call due to https://bugs.php.net/bug.php?id=71615
            $mimetype = @finfo_file($finfo, $filename);
            finfo_close($finfo);
            if ($mimetype) {
                return $mimetype;
            }
        }

        return 'application/octet-stream';
    }
}
