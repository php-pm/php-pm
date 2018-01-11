<?php

namespace PHPPM;

use React\EventLoop\LoopInterface;
use React\Socket\UnixConnector;
use React\Socket\TimeoutConnector;
use React\Socket\ConnectionInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RequestHandler
{
    use ProcessCommunicationTrait;

    /**
     * @var float
     */
    protected $start;

    /**
     * @var ConnectionInterface
     */
    private $incoming;

    /**
     * @var ConnectionInterface
     */
    private $connection;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var SlavePool
     */
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

    public function __construct($socketPath, LoopInterface $loop, OutputInterface $output, SlavePool $slaves)
    {
        $this->setSocketPath($socketPath);

        $this->loop = $loop;
        $this->output = $output;
        $this->slaves = $slaves;
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
            $remoteAddress = (string) $this->incoming->getRemoteAddress();
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
     * Get next free slave from pool
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
        } else {
            // keep retrying until slave becomes available
            $this->loop->futureTick([$this, 'getNextSlave']);
        }
    }

    /**
     * Slave available handler
     *
     * @param Slave $slave available slave instance
     */
    public function slaveAvailable(Slave $slave)
    {
        $this->redirectionTries++;

        // client went away while waiting for worker
        if (!$this->connectionOpen) {
            return;
        }

        $this->slave = $slave;

        $this->verboseTimer(function ($took) {
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
     * @param ConnectionInterface $connection Slave connection
     */
    public function slaveConnected(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        $this->verboseTimer(function ($took) {
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
    public function slaveClosed()
    {
        $this->verboseTimer(function ($took) {
            return sprintf('<info>Worker %d took abnormal %.3f seconds for handling a connection</info>', $this->slave->getPort(), $took);
        });

        $this->incoming->end();

        // if slave has already closed its connection to master,
        // it probably died and is already terminated
        if ($this->slave->getStatus() !== Slave::CLOSED) {
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
    }

    /**
     * Handle failed slave connection
     *
     * Connection may fail because of timeouts or crashed or dying worker.
     * Since the worker may only very busy or dying it's put back into the
     * available worker list. If it is really dying it will be removed from the
     * worker list by the connection:close event.
     *
     * @param \Exception $e slave connection error
     */
    public function slaveConnectFailed(\Exception $e)
    {
        $this->slave->release();

        $this->verboseTimer(function ($took) use ($e) {
            return sprintf(
                '<error>Connection to worker %d failed. Try #%d, took %.3fs ' .
                '(timeout %ds). Error message: [%d] %s</error>',
                $this->slave->getPort(),
                $this->redirectionTries,
                $took,
                $this->timeout,
                $e->getCode(),
                $e->getMessage()
            );
        }, true);

        // should not get any more access to this slave instance
        unset($this->slave);

        // try next free slave, let loop schedule it (stack friendly)
        // after 10th retry add 10ms delay, keep increasing until timeout
        $delay = min($this->timeout, floor($this->redirectionTries / 10) / 100);
        $this->loop->addTimer($delay, [$this, 'getNextSlave']);
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
        if (($always || $took > 1) && $this->output->isVeryVerbose()) {
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
    protected function isHeaderEnd($buffer)
    {
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
    protected function replaceHeader($header, $headersToReplace)
    {
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
