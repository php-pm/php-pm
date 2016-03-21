<?php

namespace PHPPM;

use React\Socket\Connection;

/**
 * Little trait used in ProcessManager and ProcessSlave to have a simple json process communication.
 */
trait ProcessCommunicationTrait
{
    /**
     * Parses a received message. Redirects to the appropriate `command*` method.
     *
     * @param array $data
     * @param Connection $conn
     *
     * @throws \Exception when invalid 'cmd' in $data.
     */
    public function processMessage($data, Connection $conn)
    {
        $array = json_decode($data, true);

        $method = 'command' . ucfirst($array['cmd']);
        if (is_callable(array($this, $method))) {
            $this->$method($array, $conn);
        } else {
            throw new \Exception(sprintf('Command %s not found. Got %s', $method, $data));
        }
    }

    /**
     * Binds data-listener to $conn and waits for incoming commands.
     *
     * @param Connection $conn
     */
    protected function bindProcessMessage(Connection $conn)
    {
        $buffer = '';

        $conn->on(
            'data',
            \Closure::bind(
                function ($data) use ($conn, &$buffer) {
                    $buffer .= $data;

                    if (substr($buffer, -1) === PHP_EOL) {
                        foreach (explode(PHP_EOL, $buffer) as $message) {
                            if ($message) {
                                $this->processMessage($message, $conn);
                            }
                        }

                        $buffer = '';
                    }
                },
                $this
            )
        );
    }

    /**
     * Sends a message through $conn.
     *
     * @param Connection $conn
     * @param string $command
     * @param array $message
     */
    protected function sendMessage(Connection $conn, $command, array $message = [])
    {
        $message['cmd'] = $command;
        $conn->write(json_encode($message) . PHP_EOL);
    }
}