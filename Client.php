<?php

namespace PHPPM;

class Client
{
    /**
     * @var int
     */
    protected $controllerPort;

    /**
     * @var \React\EventLoop\ExtEventLoop|\React\EventLoop\LibEventLoop|\React\EventLoop\LibEvLoop|\React\EventLoop\StreamSelectLoop
     */
    protected $loop;

    /**
     * @var \React\Socket\Connection
     */
    protected $connection;

    public function __construct($controllerPort = 5500)
    {
        $this->controllerPort = $controllerPort;
        $this->loop = \React\EventLoop\Factory::create();
    }

    /**
     * @return \React\Socket\Connection
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }
        $client = stream_socket_client('tcp://127.0.0.1:' . $this->controllerPort);
        $this->connection = new \React\Socket\Connection($client, $this->loop);
        return $this->connection;
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;
        $connection = $this->getConnection();

        $result = '';
        $connection->on('data', function($data) use ($result) {
            $result .= $data;
        });

        $connection->on('close', function() use ($callback, $result) {
            $callback($result);
        });

        $connection->write(json_encode($data));
    }

    public function getStatus(callable $callback)
    {
        $this->request('status', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
    }

}