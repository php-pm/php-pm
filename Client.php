<?php

namespace PHPPM;

use Amp\ByteStream\Message;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use function Amp\call;
use function Amp\Socket\connect;

class Client
{
    use ProcessCommunicationTrait;

    /**
     * @var int
     */
    protected $controllerPort;

    /**
     * @var Socket
     */
    protected $socket;

    /**
     * @var Promise
     */
    protected $socketPromise;

    public function __construct($controllerPort = ProcessManager::CONTROLLER_PORT)
    {
        $this->controllerPort = $controllerPort;
    }

    protected function getSocket(): Promise
    {
        if ($this->socket) {
            return new Success($this->socket);
        }

        if ($this->socketPromise) {
            return $this->socketPromise;
        }

        $socketUri = $this->getControllerSocket();
        $socketPromise = $this->socketPromise = connect($socketUri);
        $socketPromise->onResolve(function () {
            $this->socketPromise = null;
        });

        return $socketPromise;
    }

    protected function request($command, $options): Promise
    {
        return call(function () use ($command, $options) {
            /** @var Socket $socket */
            $socket = yield $this->getSocket();

            $data['cmd'] = $command;
            $data['options'] = $options;

            yield $socket->write(json_encode($data) . PHP_EOL);

            return new Message($socket); // auto-buffer and resolve with buffered string
        });
    }

    public function getStatus(callable $callback)
    {
        $result = Promise\wait($this->request('status', []));
        $callback(json_decode($result, true));
    }

    /**
     * @return string
     */
    protected function getControllerSocket()
    {
        $host = $this->getNewControllerHost(false);
        $port = $this->controllerPort;

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $localSocket = 'tcp://' . $host . ':' . $port;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // enclose IPv6 addresses in square brackets before appending port
            $localSocket = 'tcp://[' . $host . ']:' . $port;
        } elseif (preg_match('#^unix://#', $host)) {
            $localSocket = $host;
        } else {
            throw new \UnexpectedValueException(
                '"' . $host . '" does not match to a set of supported transports. ' .
                'Supported transports are: IPv4, IPv6 and unix:// .'
                , 1433253311);
        }

        return $localSocket;
    }

    public function stopProcessManager(callable $callback)
    {
        $result = Promise\wait($this->request('stop', []));
        $callback(json_decode($result, true));
    }
}
