<?php

namespace PHPPM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\UnixConnector;
use React\Socket\ConnectionInterface;

class Client
{
    use ProcessCommunicationTrait;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var ConnectionInterface
     */
    protected $connection;

    public function __construct()
    {
        $this->loop = Factory::create();
    }

    /**
     * @return ConnectionInterface
     */
    protected function getConnection()
    {
        if ($this->connection) {
            $this->connection->close();
            unset($this->connection);
        }

        $connector = new Connector($this->loop);
        $unixSocket = $this->getNewControllerHost(false);

        return $connector->connect($unixSocket)->done(
            function($connection) {
                $this->connection = $connection;
                return $this->connection;
            }
        );
    }

    protected function request($command, $options, $callback)
    {
        $data['cmd'] = $command;
        $data['options'] = $options;

        $this->getConnection()->done(
            function($connection) use ($data) {
                $result = '';

                $connection->on('data', function($data) use (&$result) {
                    $result .= $data;
                });

                $connection->on('close', function() use ($callback, &$result) {
                    $callback($result);
                });

                $connection->write(json_encode($data) . PHP_EOL);
            }
        );
    }

    public function getStatus(callable $callback)
    {
        $this->request('status', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }

    public function stopProcessManager(callable $callback)
    {
        $this->request('stop', [], function($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }
}
