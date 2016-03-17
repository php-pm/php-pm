<?php
declare(ticks = 1);

namespace PHPPM;

class ProcessSlave
{
    /**
     * @var \React\Socket\Server
     */
    protected $server;

    /**
     * @var \React\EventLoop\LibEventLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var resource
     */
    protected $client;

    /**
     * @var \React\Socket\Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $bridgeName;

    /**
     * @var Bridges\BridgeInterface
     */
    protected $bridge;

    protected $logFormat = '$remote_addr - $remote_user [$time_local] "$request" $status $bytes_sent "$http_referer" "$http_user_agent"';

    /**
     * Contains some configuration options.
     *
     * 'appenv' => string (App environment)
     * 'static' => boolean (true) (If it should server static files)
     * 'logging' => boolean (false) (If it should log all requests)
     *
     *
     * @var array
     */
    protected $config;

    public function __construct($bridgeName = null, $appBootstrap, array $config = [])
    {
        gc_disable();
        $this->config = $config;
        $this->bridgeName = $bridgeName;
        $this->bootstrap($appBootstrap, $config['app-env'], $this->isDebug());
        $this->connectToMaster();

        if ($this->isDebug()) {
            $this->sendCurrentFiles();
        }

        $this->loop->run();
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
        if ($this->connection->isWritable()) {
            $this->connection->close();
        }
        $this->server->shutdown();
        $this->loop->stop();
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

    protected function bootstrap($appBootstrap, $appenv, $debug)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv, $debug);
        }
    }

    /**
     * Sends to the master a snapshot of current known php files, so it can track those files and restart
     * slaves if necessary.
     */
    protected function sendCurrentFiles(){
        $this->connection->write(json_encode(array('cmd' => 'files', 'files' => get_included_files())) . PHP_EOL);
    }

    /**
     * Connects to ProcessManager, master process.
     */
    public function connectToMaster()
    {
        $this->loop = \React\EventLoop\Factory::create();
        $this->client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new \React\Socket\Connection($this->client, $this->loop);
        $this->connection->on('error', function ($data) {
            var_dump($data);
        });

        $pcntl = new \MKraemer\ReactPCNTL\PCNTL($this->loop);

        $pcntl->on(SIGTERM, [$this, 'shutdown']);
        $pcntl->on(SIGINT, [$this, 'shutdown']);

        $this->connection->on(
            'close',
            \Closure::bind(
                function () {
                    $this->shutdown();
                },
                $this
            )
        );

        $this->server = new \React\Socket\Server($this->loop);
        $this->server->on('error', function ($data) {
            var_dump($data);
        });

        $http = new ReactServerWrapper($this->server);
        $http->on('request', array($this, 'onRequest'));
        $http->on('error', function ($data) {
            var_dump($data);
        });

        $port = $this->config['port'];
        while (true) {
            try {
                $this->server->listen($port);
                break;
            } catch (\React\Socket\ConnectionException $e) {
                usleep(500);
            }
        }

        $this->connection->write(json_encode(array('cmd' => 'register', 'pid' => getmypid(), 'port' => $port)) . PHP_EOL);
    }

    /**
     * Handles incoming requests and transforms a $request into a $response by reference.
     *
     * @param \React\Http\Request $request
     * @param ReactResponseWrapper $response
     *
     * @throws \Exception
     */
    public function onRequest(\React\Http\Request $request, ReactResponseWrapper $response)
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

    protected function handleRequest(\React\Http\Request $request, ReactResponseWrapper $response)
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
    }

    protected function prepareEnvironment(\React\Http\Request $request)
    {
        $_SERVER['REQUEST_METHOD'] = $request->getMethod();
        $_SERVER['REQUEST_TIME'] = (int)microtime(true);
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['QUERY_STRING'] = $request->getQuery();

        $_SERVER['HTTP_HOST'] = @$request->getHeaders()['Host'];
        $_SERVER['HTTP_CONNECTION'] = @$request->getHeaders()['Connection'];
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = @$request->getHeaders()['Accept-Language'];
        $_SERVER['HTTP_ACCEPT_ENCODING'] = @$request->getHeaders()['Accept-Encoding'];
        $_SERVER['HTTP_ACCEPT_CHARSET'] = @$request->getHeaders()['Accept-Charset'];
        $_SERVER['HTTP_ACCEPT'] = @$request->getHeaders()['Accept'];
        $_SERVER['HTTP_REFERER'] = @$request->getHeaders()['REFERER'];
        $_SERVER['HTTP_USER_AGENT'] = @$request->getHeaders()['User-Agent'];
        $_SERVER['REMOTE_ADDR'] = @$request->remoteAddress;
        $_SERVER['REQUEST_URI'] = @$request->getPath();
    }

    /**
     * @param \React\Http\Request $request
     * @param ReactResponseWrapper $response
     * @return bool returns true if successfully served
     */
    protected function serveStatic(\React\Http\Request $request, ReactResponseWrapper $response)
    {

        $filePath = $this->getBridge()->getStaticDirectory() . $request->getPath();

        if (substr($filePath, -4) !== '.php' && is_file($filePath)) {

            $response->writeHead(200, [
                'Content-Type' => $this->mimeContentType($filePath),
                'Content-Length' => filesize($filePath),
//                'Path' => $filePath
            ]);
            $response->end(file_get_contents($filePath));
            return true;
        }

        return false;
    }

    protected function setupResponseLogging(\React\Http\Request $request, ReactResponseWrapper $response)
    {
        $timeLocal = date('d/M/Y:H:i:s O');

        $response->on('end', function () use ($request, $response, $timeLocal) {
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
                $request->getMethod() . ' ' . $request->getPath() . ' HTTP/' . $request->getHttpVersion(),
                $response->getStatusCode(),
                $response->getBytesSent(),
                @$request->getHeaders()['Referer'] ?: '-',
                @$request->getHeaders()['User-Agent'] ?: '-',
            ],
                $this->logFormat);

            $this->connection->write(json_encode(array('cmd' => 'log', 'message' => $message)) . PHP_EOL);
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
