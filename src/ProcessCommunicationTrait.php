<?php

namespace PHPPM;

use React\Socket\ConnectionInterface;

/**
 * Little trait used in ProcessManager and ProcessSlave to have a simple json process communication.
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
     * @param ConnectionInterface $conn
     * @param array $data
     *
     * @throws \Exception when invalid 'cmd' in $data.
     */
    public function processMessage(ConnectionInterface $conn, $data)
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
     * @param ConnectionInterface $conn
     */
    protected function bindProcessMessage(ConnectionInterface $conn)
    {
        $buffer = '';

        $conn->on('data', function ($data) use ($conn, &$buffer) {
            $buffer .= $data;

            if (substr($buffer, -strlen(PHP_EOL)) === PHP_EOL) {
                foreach (explode(PHP_EOL, $buffer) as $message) {
                    if ($message) {
                        $this->processMessage($conn, $message);
                    }
                }

                $buffer = '';
            }
        });
    }

    /**
     * Sends a message through $conn.
     *
     * @param ConnectionInterface $conn
     * @param string $command
     * @param array $message
     */
    protected function sendMessage(ConnectionInterface $conn, $command, array $message = [])
    {
        $message['cmd'] = $command;
        $conn->write(json_encode($message) . PHP_EOL);
    }

    /**
     *
     * @param string $affix
     * @param bool $overwrite
     * @return string
     */
    protected function getSockFile($affix, $overwrite)
    {
        //since all commands set setcwd() we can make sure we are in the current application folder

        if ('/' === substr($this->socketPath, 0, 1)) {
            $run = $this->socketPath;
        } else {
            $run = getcwd() . '/' . $this->socketPath;
        }

        if ('/' !== substr($run, -1)) {
            $run .= '/';
        }

        if (!is_dir($run) && !mkdir($run, 0777, true)) {
            throw new \RuntimeException(sprintf('Could not create %s folder.', $run));
        }

        $sock = $run. $affix . '.sock';

        if ($overwrite && file_exists($sock)) {
            unlink($sock);
        }

        return 'unix://' . $sock;
    }

    /**
     * @param int $port
     *
     * @return string
     */
    protected function getSlaveSocketPath($port, $overwrite = false)
    {
        return $this->getSockFile($port, $overwrite);
    }

    /**
     * @param bool $overwrite
     * @return string
     */
    protected function getControllerSocketPath($overwrite = true)
    {
        return $this->getSockFile('controller', $overwrite);
    }

    /**
     * @param string $socketPath
     */
    public function setSocketPath($socketPath)
    {
        $this->socketPath = $socketPath;
    }
}
