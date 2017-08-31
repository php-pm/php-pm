<?php

namespace PHPPM;

use Amp\Promise;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;

/**
 * Little trait used in ProcessManager and ProcessSlave to have a simple JSON process communication.
 */
trait ProcessCommunicationTrait
{
    /**
     * Path to socket folder.
     *
     * @var string
     */
    protected $socketPath = '.ppm/run/';

    /**
     * Parses a received message. Redirects to the appropriate `command*` method.
     *
     * @param Socket $socket Socket where this message has been received.
     * @param string $data JSON encoded message.
     *
     * @throws \Exception If $data contains an invalid 'cmd' entry.
     */
    public function processMessage(Socket $socket, string $data)
    {
        $array = json_decode($data, true);
        $method = 'command' . $array['cmd'];

        if (!is_callable(array($this, $method))) {
            throw new \Exception(sprintf('Command %s not found. Got %s', $method, $data));
        }

        $this->$method($socket, $array);
    }

    /**
     * Reads data from the socket and parses it.
     *
     * @param Socket $socket Socket to read data from.
     *
     * @return \Generator
     */
    protected function receiveProcessMessages(Socket $socket): \Generator
    {
        $buffer = '';

        while (null !== $chunk = yield $socket->read()) {
            $buffer .= $chunk;

            while (false !== $eolPosition = \strpos($buffer, \PHP_EOL)) {
                $message = substr($buffer, 0, $eolPosition);
                $buffer = \substr($buffer, $eolPosition + \strlen(\PHP_EOL));

                $this->processMessage($socket, $message);
            }
        }
    }

    /**
     * Sends a message on the specified socket.
     *
     * @param Socket $socket Socket to send the message to.
     * @param string $command Command to send.
     * @param array  $message
     *
     * @return Promise Resolves once the write is complete.
     */
    protected function sendMessage(Socket $socket, string $command, array $message = []): Promise
    {
        $message['cmd'] = $command;

        return $socket->write(json_encode($message) . PHP_EOL);
    }

    /**
     * @param string $socketPath
     */
    public function setSocketPath($socketPath)
    {
        $this->socketPath = $socketPath;
    }
}
