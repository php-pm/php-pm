<?php

namespace PHPPM;

class ProcessSlave
{

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

    /**
     * @var string|null
     */
    protected $appenv;

    public function __construct($bridgeName = null, $appBootstrap, $appenv)
    {
        $this->bridgeName = $bridgeName;
        $this->bootstrap($appBootstrap, $appenv);
        $this->connectToMaster();
        $this->loop->run();
    }

    protected function shutdown()
    {
        echo "SHUTTING SLAVE PROCESS DOWN\n";
        $this->bye();
        exit;
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

    protected function bootstrap($appBootstrap, $appenv)
    {
        if ($bridge = $this->getBridge()) {
            $bridge->bootstrap($appBootstrap, $appenv);
        }
    }

    public function connectToMaster()
    {
        $this->loop = \React\EventLoop\Factory::create();
        $this->client = stream_socket_client('tcp://127.0.0.1:5500');
        $this->connection = new \React\Socket\Connection($this->client, $this->loop);

        $this->connection->on(
            'close',
            \Closure::bind(
                function () {
                    $this->shutdown();
                },
                $this
            )
        );

        $socket = new \React\Socket\Server($this->loop);
        $http = new \React\Http\Server($socket);
        $http->on('request', array($this, 'onRequest'));

        $port = 5501;
        while ($port < 5600) {
            try {
                $socket->listen($port);
                break;
            } catch( \React\Socket\ConnectionException $e ) {
                $port++;
            }
        }

        $this->connection->write(json_encode(array('cmd' => 'register', 'pid' => getmypid(), 'port' => $port)));
    }

    public function onRequest(\React\Http\Request $request, \React\Http\Response $response)
    {
        if ($bridge = $this->getBridge()) {
            $request->on('data', array($bridge, 'onData'));
            return $bridge->onRequest($request, $response);
        } else {
            $response->writeHead('404');
            $response->end('No Bridge Defined.');
        }
    }

    public function bye()
    {
        if ($this->connection->isWritable()) {
            $this->connection->write(json_encode(array('cmd' => 'unregister', 'pid' => getmypid())));
            $this->connection->close();
        }
        $this->loop->stop();
    }
}
