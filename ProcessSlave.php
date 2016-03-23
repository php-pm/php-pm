<?php
declare(ticks = 1);

namespace PHPPM;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPPM\React\HttpResponse;
use PHPPM\React\HttpServer;
use React\Socket\Connection;
use Symfony\Component\Debug\BufferingLogger;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Debug\ErrorHandler;

class ProcessSlave
{
    use ProcessCommunicationTrait;

    /**
     * The HTTP Server.
     *
     * @var React\Server
     */
    protected $server;

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * Connection to ProcessManager, master process.
     *
     * @var \React\Socket\Connection
     */
    protected $controller;

    /**
     * @var string
     */
    protected $bridgeName;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

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

    protected $logFormat = '[$time_local] $remote_addr - $remote_user "$request" $status $bytes_sent "$http_referer"';

    /**
     * Contains some configuration options.
     *
     * 'port' => int (server port)
     * 'appenv' => string (App environment)
     * 'static' => boolean (true) (If it should server static files)
     * 'logging' => boolean (false) (If it should log all requests)
     * ...
     *
     * @var array
     */
    protected $config;

    public function __construct($bridgeName = null, $appBootstrap, array $config = [])
    {
        gc_disable();
        $this->config = $config;
        $this->appBootstrap = $appBootstrap;
        $this->bridgeName = $bridgeName;

        if ($this->config['session_path']) {
            session_save_path($this->config['session_path']);
        }

        $this->run();
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

        $this->inShutdown = true;

        $this->sendCurrentFiles();
        $this->loop->tick();

        if ($this->controller && $this->controller->isWritable()) {
            $this->controller->close();
        }
        if ($this->server) {
            @$this->server->shutdown();
        }
        if ($this->loop) {
            $this->loop->tick();
            @$this->loop->stop();
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
     * @param string $appBootstrap
     * @param string $appenv
     * @param boolean $debug
     *
     * @throws \Exception
     */
    protected function bootstrap($appBootstrap, $appenv, $debug)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv, $debug);
            $this->sendMessage($this->controller, 'ready');
        }
    }

    /**
     * Sends to the master a snapshot of current known php files, so it can track those files and restart
     * slaves if necessary.
     */
    protected function sendCurrentFiles()
    {
        $files = get_included_files();
        $flipped = array_flip($files);

        //speedy way checking if two arrays are different.
        if (!$this->lastSentFiles || array_diff_key($flipped, $this->lastSentFiles)) {
            $this->lastSentFiles = $flipped;
            $this->sendMessage($this->controller, 'files', ['files' => $files]);
        }
    }

    /**
     * Connects to ProcessManager, master process.
     */
    public function run()
    {
        $this->loop = \React\EventLoop\Factory::create();

        ErrorHandler::register(new ErrorHandler(new BufferingLogger()));

        $client = stream_socket_client($this->config['controllerHost']);
        $this->controller = new \React\Socket\Connection($client, $this->loop);
        $this->controller->on('error', function ($data) {
            var_dump($data);
        });

        $pcntl = new \MKraemer\ReactPCNTL\PCNTL($this->loop);

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
        $this->server->on('error', function ($data) {
            var_dump($data);
        });

        $http = new HttpServer($this->server);
        $http->on('request', array($this, 'onRequest'));
        $http->on('error', function ($data) {
            var_dump($data);
        });

        $port = $this->config['port'];
        $host = $this->config['host'];

        while (true) {
            try {
                $this->server->listen($port, $host);
                break;
            } catch (\React\Socket\ConnectionException $e) {
                usleep(500);
            }
        }

        $this->sendMessage($this->controller, 'register', ['pid' => getmypid(), 'port' => $port]);

        $this->loop->run();
    }

    public function commandBootstrap(array $data, Connection $conn)
    {
        $this->bootstrap($this->appBootstrap, $this->config['app-env'], $this->isDebug());

        if ($this->isDebug()) {
            $this->sendCurrentFiles();
        }
    }

    /**
     * Handles incoming requests and transforms a $request into a $response by reference.
     *
     * @param \React\Http\Request $request
     * @param HttpResponse $response
     *
     * @throws \Exception
     */
    public function onRequest(\React\Http\Request $request, HttpResponse $response)
    {
        $this->prepareEnvironment($request);

        if ($this->isLogging()) {
            $this->setupResponseLogging($request, $response);
        }

        $this->handleRequest($request, $response);

        if (memory_get_peak_usage(true) / 1024 / 1024 > substr(ini_get('memory_limit'), 0, -1) / 2) {
            gc_collect_cycles();
//            echo sprintf("%d - (%s/%s)\n", getmypid(), memory_get_peak_usage(true) / 1024 / 1024, ini_get('memory_limit'));
        }
    }

    protected function handleRequest(\React\Http\Request $request, HttpResponse $response)
    {
        if ($bridge = $this->getBridge()) {

            if ($this->isServingStatic()) {
                if (true === $this->serveStatic($request, $response)) {
                    return;
                }
            }

            $bridge->onRequest($request, $response);

            if ($this->isDebug()) {
                $this->sendCurrentFiles();
            }
        } else {
            $response->writeHead('404');
            $response->end('No Bridge Defined.');
        }

        if (headers_sent()) {
            //when a script sent headers the cgi process needs to die because the second request
            //trying to send headers again will fail (headers already sent fatal). Its best to not even
            //try to send headers because this break the whole of approach of php-pm using php-cgi.
            error_log('Headers has been sent. Force restart of a worker. Make sure your application does not send headers on its own.');
            $this->shutdown();
        }
    }

    protected function prepareEnvironment(\React\Http\Request $request)
    {
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_TIME'] = (int)microtime(true);
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['QUERY_STRING'] = http_build_query($request->getQuery());

        foreach ($request->getHeaders() as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $_SERVER['REMOTE_ADDR'] = $request->remoteAddress;

        $_SERVER['SERVER_NAME'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $_SERVER['REQUEST_URI'] = $request->getPath();
        $_SERVER['DOCUMENT_ROOT'] = isset($_ENV['DOCUMENT_ROOT']) ? $_ENV['DOCUMENT_ROOT'] : getcwd();
        $_SERVER['SCRIPT_NAME'] = isset($_ENV['SCRIPT_NAME']) ? $_ENV['SCRIPT_NAME'] : 'index.php';
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'];
    }

    /**
     * @param \React\Http\Request $request
     * @param HttpResponse $response
     * @return bool returns true if successfully served
     */
    protected function serveStatic(\React\Http\Request $request, HttpResponse $response)
    {
        $filePath = $this->getBridge()->getStaticDirectory() . $request->getPath();

        if (substr($filePath, -4) !== '.php' && is_file($filePath)) {

            $response->writeHead(200, [
                'Content-Type' => $this->mimeContentType($filePath),
                'Content-Length' => filesize($filePath),
            ]);
            $response->end(file_get_contents($filePath));
            return true;
        }

        return false;
    }

    protected function setupResponseLogging(\React\Http\Request $request, HttpResponse $response)
    {
        $timeLocal = date('d/M/Y:H:i:s O');

        $response->on('end', function () use ($request, $response, $timeLocal) {

            $requestString = $request->getMethod() . ' ' . $request->getPath() . ' HTTP/' . $request->getHttpVersion();
            $statusCode = $response->getStatusCode();

            if ($response->getStatusCode() < 400) {
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
                $request->remoteAddress,
                '-', //todo remote_user
                $timeLocal,
                $requestString,
                $statusCode,
                $response->getBytesSent(),
                isset($request->getHeaders()['Referer']) ? $request->getHeaders()['Referer'] : '-',
                isset($request->getHeaders()['User-Agent']) ? $request->getHeaders()['User-Agent'] : '-',
            ],
                $this->logFormat);

            if ($response->getStatusCode() >= 400) {
                $message = "<error>$message</error>";
            }


            $this->sendMessage($this->controller, 'log', ['message' => $message]);
        });
    }

    /**
     * @param string $filename
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
        if (array_key_exists($ext, $mimeTypes)) {
            return $mimeTypes[$ext];
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME);
            $mimetype = finfo_file($finfo, $filename);
            finfo_close($finfo);
            return $mimetype;
        } else {
            return 'application/octet-stream';
        }
    }
}
