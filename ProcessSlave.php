<?php
declare(ticks = 1);

namespace PHPPM;

use MKraemer\ReactPCNTL\PCNTL;
use PHPPM\Bridges\BridgeInterface;
use PHPPM\Debug\BufferingLogger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server;
use React\Stream\DuplexResourceStream;
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
     * @var React\Server
     */
    protected $server;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * DuplexResourceStream to ProcessManager, master process.
     *
     * @var DuplexResourceStream
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
        $this->watchedFiles[] = $path;
    }

    /**
     * Sends to the master a snapshot of current known php files, so it can track those files and restart
     * slaves if necessary.
     */
    protected function sendCurrentFiles()
    {
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

        while (true) {
            try {
                $client = stream_socket_client($this->config['controllerHost']);
                break;
            } catch (\Exception $e) {
                usleep(500);
            }
        }
        $this->controller = new DuplexResourceStream($client, $this->loop);

        $pcntl = new PCNTL($this->loop);

        $pcntl->on(SIGTERM, [$this, 'shutdown']);
        $pcntl->on(SIGINT, [$this, 'shutdown']);
        register_shutdown_function([$this, 'shutdown']);

        $this->bindProcessMessage($this->controller);
        $this->controller->on(
            'close',
            \Closure::bind(
                function () {
                    $this->shutdown();
                },
                $this
            )
        );

        $this->server = new React\Server($this->loop); //our version for now, because of unix socket support

        new Server($this->server, [$this, 'onRequest']);

        //port is only used for tcp connection. If unix socket, 'host' contains the socket path
        $port = $this->config['port'];
        $host = $this->config['host'];

        while (true) {
            try {
                $this->server->listen($port, $host);
                break;
            } catch (\Exception $e) {
                usleep(500);
            }
        }

        $this->sendMessage($this->controller, 'register', ['pid' => getmypid(), 'port' => $port]);

        $this->loop->run();
    }

    public function commandBootstrap(array $data, DuplexResourceStream $conn)
    {
        $this->bootstrap($this->appBootstrap, $this->config['app-env'], $this->isDebug());

        if ($this->isDebug()) {
            $this->sendCurrentFiles();
        }
    }

    /**
     * Handles incoming requests and transforms a $request into a $response by reference.
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     * @throws \Exception
     */
    public function onRequest(RequestInterface $request)
    {
        $this->prepareEnvironment($request);

        $response = $this->handleRequest($request);

        if ($this->isLogging()) {
            $this->logResponse($request, $response);
        }
        return $response;
    }

    /**
     * Handle a redirected request from master.
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    protected function handleRequest(RequestInterface $request)
    {
        if ($this->getStaticDirectory()) {
            $staticResponse = $this->serveStatic($request);
            if ($staticResponse instanceof ResponseInterface) {
                return $staticResponse;
            }
        }

        if ($bridge = $this->getBridge()) {

            $response = $bridge->onRequest($request);

            if ($this->isDebug()) {
                $this->sendCurrentFiles();
            }
        } else {
            $response = new Response(404);
            $response->getBody()->write('No Bridge Defined.');
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

    protected function prepareEnvironment(RequestInterface $request)
    {
        $_SERVER = $this->baseServer;
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_TIME'] = (int)microtime(true);
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        $_SERVER['QUERY_STRING'] = $request->getUri()->getQuery();

        foreach ($request->getHeaders() as $name => $valueArr) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $request->getHeaderLine($name);
        }

        //We receive X-PHP-PM-Remote-IP from ProcessManager.
        //This header is only used to proxy the remoteAddress from master -> slave.
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_PHP_PM_REMOTE_IP'];
        unset($_SERVER['HTTP_X_PHP_PM_REMOTE_IP']);

        $_SERVER['SERVER_NAME'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $_SERVER['REQUEST_URI'] = $request->getUri()->getPath();
        $_SERVER['DOCUMENT_ROOT'] = isset($_ENV['DOCUMENT_ROOT']) ? $_ENV['DOCUMENT_ROOT'] : getcwd();
        $_SERVER['SCRIPT_NAME'] = isset($_ENV['SCRIPT_NAME']) ? $_ENV['SCRIPT_NAME'] : 'index.php';
        $_SERVER['SCRIPT_FILENAME'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $_SERVER['SCRIPT_NAME'];
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface|false returns ResponseInterface if successfully served, false otherwise
     */
    protected function serveStatic(RequestInterface $request)
    {
        $filePath = $this->getStaticDirectory() . $request->getUri()->getPath();

        if (substr($filePath, -4) !== '.php' && is_file($filePath) && is_readable($filePath)) {

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

    protected function logResponse(RequestInterface $request, ResponseInterface $response)
    {
        $timeLocal = date('d/M/Y:H:i:s O');

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
            $_SERVER['REMOTE_ADDR'],
            '-', //todo remote_user
            $timeLocal,
            $requestString,
            $statusCode,
            $response->getBody()->getSize(),
            $request->hasHeader('Referer') ? $request->getHeaderLine('Referer') : '-',
            $request->hasHeader('User-Agent') ? $request->getHeaderLine('User-Agent') : '-'
        ],
            $this->logFormat);

        if ($response->getStatusCode() >= 400) {
            $message = "<error>$message</error>";
        }


        $this->sendMessage($this->controller, 'log', ['message' => $message]);
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
