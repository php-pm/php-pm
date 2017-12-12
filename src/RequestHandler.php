<?php

namespace PHPPM;

use React\Socket\UnixConnector;
use React\Socket\TimeoutConnector;
use React\Socket\ConnectionInterface;

class RequestHandler
{
    use ProcessCommunicationTrait;

    /**
     * @var ConnectionInterface
     */
    private $incoming;

    /**
     * @var ConnectionInterface
     */
    private $connection;

    /*
     * ProcessManager properties
     */
    private $loop;
    private $output;
    private $slaves;

    /**
     * Timeout in seconds for master to worker connection.
     *
     * @var int
     */
    private $timeout = 10;

    /**
     * @var Slave instance
     */
    private $slave;

    private $connectionOpen = true;
    private $redirectionTries = 0;
    private $incomingBuffer = '';

    public function __construct(ProcessManager $processManager)
    {
        $this->loop = $processManager->loop;
        $this->output = $processManager->output;
        $this->slaves = $processManager->slaves;
    }

    /**
     * Handle incoming client connection
     *
     * @param ConnectionInterface $incoming
     */
    public function handle(ConnectionInterface $incoming)
    {
        $this->incoming = $incoming;

        $this->incoming->on('data', [$this, 'handleData']);
        $this->incoming->on('close', function () {
            $this->connectionOpen = false;
        });

        $this->start = microtime(true);
        $this->getNextSlave();
    }

    /**
     * Buffer incoming data until slave connection is available
     * and headers have been received
     *
     * @param string $data
     */
    public function handleData($data)
    {
        $this->incomingBuffer .= $data;

        if ($this->connection && $this->isHeaderEnd($this->incomingBuffer)) {
            $remoteAddress = $this->incoming->getRemoteAddress();
            $headersToReplace = [
                'X-PHP-PM-Remote-IP' => trim(parse_url($remoteAddress, PHP_URL_HOST), '[]'),
                'X-PHP-PM-Remote-Port' => trim(parse_url($remoteAddress, PHP_URL_PORT), '[]')
            ];

            $buffer = $this->replaceHeader($this->incomingBuffer, $headersToReplace);
            $this->connection->write($buffer);

            $this->incoming->removeListener('data', [$this, 'handleData']);
            $this->incoming->pipe($this->connection);
        }
    }

    /**
     * Get next free slave from process manager.
     * Asynchronously keep trying until slave becomes available
     */
    public function getNextSlave()
    {
        $available = $this->slaves->getByStatus(Slave::READY);

        if (count($available)) {
            // pick first slave
            $slave = array_shift($available);

            // slave available -> connect
            $this->slaveAvailable($slave);
        }
        else {
            // keep retrying until slave becomes available
            $this->loop->futureTick([$this, 'getNextSlave']);
        }
    }

    /**
     * Slave available handler
     *
     * @param array $slave available slave instance
     */
    public function slaveAvailable(Slave $slave)
    {
        $this->redirectionTries++;

        // client went away while waiting for worker
        if (!$this->connectionOpen) {
            return;
        }

        $this->slave = $slave;

        $this->verboseTimer(function($took) {
            return sprintf('<info>took abnormal %.3f seconds for choosing next free worker</info>', $took);
        });

        // mark slave as busy
        $this->slave->occupy();

        $connector = new UnixConnector($this->loop);
        $connector = new TimeoutConnector($connector, $this->timeout, $this->loop);

        $socketPath = $this->getSlaveSocketPath($this->slave->getPort());
        $connector->connect($socketPath)->then(
            [$this, 'slaveConnected'],
            [$this, 'slaveConnectFailed']
        );
    }

    /**
     * Handle successful slave connection
     *
     * @param ConnectionInterface slave connection
     */
    public function slaveConnected(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $this->verboseTimer(function($took) {
            return sprintf('<info>Took abnormal %.3f seconds for connecting to worker %d</info>', $took, $this->slave->getPort());
        });

        // call handler once in case entire request as already been buffered
        $this->handleData('');

        // close slave connection when client goes away
        $this->incoming->on('close', [$this->connection, 'close']);

        // update slave availability
        $this->connection->on('close', [$this, 'slaveClosed']);

        // relay data to client
        $this->connection->pipe($this->incoming);
    }

    /**
     * Handle slave disconnected
     *
     * Typically called after slave has finished handling request
     */
    public function slaveClosed() {
        $this->verboseTimer(function($took) {
            return sprintf('<info>Worker %d took abnormal %.3f seconds for handling a connection</info>', $this->slave->getPort(), $took);
        });

        $this->incoming->end();

        // mark slave as available
        $this->slave->release();

        /** @var ConnectionInterface $connection */
        $connection = $this->slave->getConnection();

        $maxRequests = $this->slave->getMaxRequests();
        if ($this->slave->getHandledRequests() >= $maxRequests) {
            $this->slave->close();
            $this->output->writeln(sprintf('Restart worker #%d because it reached max requests of %d', $this->slave->getPort(), $maxRequests));
            $connection->close();
        }
    }

    /**
     * Handle failed slave connection
     *
     * Connection may fail because of timeouts or crashed or dying worker.
     * Since the worker may only very busy or dying it's put back into the
     * available worker list. If it is really dying it will be removed from the
     * worker list by the connection:close event.
     *
     * @param \Exception slave connection error
     */
    public function slaveConnectFailed(\Exception $e) {
        $this->slave->ready();

        $this->verboseTimer(function($took) {
            return sprintf(
                '<error>Connection to worker %d failed. Try #%d, took %.3fs. ' .
                'Try increasing your timeout of %d. Error message: [%d] %s</error>',
                $this->slave->getPort(), $this->redirectionTries, $took, $this->timeout, $e->getMessage(), $e->getCode()
            );
        }, true);

        // should not get any more access to this slave instance
        unset($this->slave);

        // Try next free client
        $this->getNextSlave([$this, 'slaveAvailable']);
    }

    /**
     * Section timer. Measure execution time hand output if verbose mode.
     *
     * @param callable $callback
     * @param bool $always Invoke callback regardless of execution time
     */
    protected function verboseTimer($callback, $always = false)
    {
        $took = microtime(true) - $this->start;
        if ($this->output->isVeryVerbose() && ($always || $took > 1)) {
            $message = $callback($took);
            $this->output->writeln($message);
        }
        $this->start = microtime(true);
    }

    /**
     * Checks whether the end of the header is in $buffer.
     *
     * @param string $buffer
     *
     * @return bool
     */
    protected function isHeaderEnd($buffer) {
        return false !== strpos($buffer, "\r\n\r\n");
    }

    /**
     * Replaces or injects header
     *
     * @param string   $header
     * @param string[] $headersToReplace
     *
     * @return string
     */
    protected function replaceHeader($header, $headersToReplace) {
        $result = $header;

        foreach ($headersToReplace as $key => $value) {
            if (false !== $headerPosition = stripos($result, $key . ':')) {
                // check how long the header is
                $length = strpos(substr($header, $headerPosition), "\r\n");
                $result = substr_replace($result, "$key: $value", $headerPosition, $length);
            } else {
                // $key is not in header yet, add it at the end
                $end = strpos($result, "\r\n\r\n");
                $result = substr_replace($result, "\r\n$key: $value", $end, 0);
            }
        }

        return $result;
    }
}
