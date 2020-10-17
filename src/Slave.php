<?php

namespace PHPPM;

use React\Socket\ConnectionInterface;
use React\ChildProcess\Process;

class Slave
{
    /*
     * Slave state model
     *
     * 1. created (slave pid not yet available)
     * 2. registered (slave pid available)
     * 3. ready (application bootstrapped)
     * 4. busy (handling request)
     * 5. closed (awaiting termination)
     * 6. locked (busy, but gracefully awaiting termination)
     */

    const ANY = 0;
    const CREATED = 1;
    const REGISTERED = 2;
    const READY = 3;
    const BUSY = 4;
    const CLOSED = 5;
    const LOCKED = 6;

    protected $socketPath;

    /**
     * Slave status
     *
     * @var int
     */
    private $status;

    /**
     * Slave port - this is an identifier mapped to a socket path
     */
    private $port;

    private $process;
    private $pid;

    /**
     * @var ConnectionInterface
     */
    private $connection; // slave incoming

    /**
     * Maximum number of requests a slave can handle
     *
     * @var int
     */
    private $maxRequests = 0;

    /**
     * Maximum amount of memory the slave can consume
     *
     * @var int
     */
    private $memoryLimit = -1;

    /**
     * Number of handled requests
     *
     * @var int
     */
    private $handledRequests = 0;

    /**
     * Amount of memory last consumed by the worker
     *
     * @var int
     */
    private $usedMemory = 0;

    /**
     * Time to live
     *
     * @var int|null
     */
    private $ttl;

    /**
     * Start timestamp
     *
     * @var int
     */
    private $startedAt;

    public function __construct($port, $maxRequests, $memoryLimit, $ttl = null)
    {
        $this->port = $port;
        $this->maxRequests = $maxRequests;
        $this->memoryLimit = $memoryLimit;
        $this->ttl = ((int) $ttl < 1) ? null : $ttl;
        $this->startedAt = \time();

        $this->status = self::CREATED;
    }

    /**
     * Attach a slave to a running process
     *
     * @param Process $process
     */
    public function attach(Process $process)
    {
        $this->process = $process;
    }

    /**
     * Register a slave after it's process started
     *
     * @param int $pid
     * @param ConnectionInterface $connection
     *
     * @return void
     */
    public function register($pid, ConnectionInterface $connection)
    {
        if ($this->status !== self::CREATED) {
            throw new \LogicException('Cannot register a slave that is not in created state');
        }

        $this->pid = $pid;
        $this->connection = $connection;

        $this->status = self::REGISTERED;
    }

    /**
     * Ready a slave after bootstrap completed
     *
     * @return void
     */
    public function ready()
    {
        if ($this->status !== self::REGISTERED) {
            throw new \LogicException('Cannot ready a slave that is not in registered state');
        }

        $this->status = self::READY;
    }

    /**
     * Occupies a slave for request handling
     *
     * @return void
     */
    public function occupy()
    {
        if ($this->status !== self::READY) {
            throw new \LogicException('Cannot occupy a slave that is not in ready state');
        }

        $this->status = self::BUSY;
    }

    /**
     * Releases a slave from request handling
     *
     * @return void
     */
    public function release()
    {
        if ($this->status !== self::BUSY) {
            throw new \LogicException('Cannot release a slave that is not in busy state');
        }

        $this->status = self::READY;
        $this->handledRequests++;
    }

    /**
     * Close slave
     *
     * Closed slaves don't accept connections anymore and are awaiting termination.
     * Closing is unconditional and does not verify slave status before closing.
     *
     * @return void
     */
    public function close()
    {
        $this->status = self::CLOSED;
    }

    /**
     * Lock slave
     *
     * Locked slaves are closed for new requests, but is finishing the current
     * request gracefully as to not interrupt the response lifecycle.
     *
     * @return void
     */
    public function lock()
    {
        if ($this->status !== self::BUSY) {
            throw new \LogicException('Cannot lock a slave that is not in busy state');
        }

        $this->status = self::LOCKED;
    }

    /**
     * Get slave status
     *
     * @return int status
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get slave port
     *
     * @return int port
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Get slave incoming connection
     *
     * @return ConnectionInterface|null slave connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get slave pid
     *
     * @return int slave pid
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * Get slave process
     *
     * @return Process slave process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * Get number of request handled by slave
     *
     * @return int handled requests
     */
    public function getHandledRequests()
    {
        return $this->handledRequests;
    }

    /**
     * Get the amount of memory the worker is currently consuming
     *
     * @return int amount of memory in MB
     */
    public function getUsedMemory()
    {
        return $this->usedMemory;
    }

    /**
     * @param int $usedMemory
     */
    public function setUsedMemory($usedMemory)
    {
        $this->usedMemory = $usedMemory;
    }

    /**
     * Get maximum number of request slave can handle
     *
     * @return int handled requests
     */
    public function getMaxRequests()
    {
        return $this->maxRequests;
    }

    /**
     * Get maximum amount of memory the slave can consume
     *
     * @return int amount of memory in MB
     */
    public function getMemoryLimit()
    {
        return $this->memoryLimit;
    }

    /**
     * If TTL was defined, make sure slave is still allowed to run
     *
     * @return bool
     */
    public function isExpired()
    {
        return null !== $this->ttl && \time() >= ($this->startedAt + $this->ttl);
    }

    /**
     * String conversion for debugging
     *
     * @return string
     */
    public function __toString()
    {
        switch ($this->status) {
            case self::CREATED:
                $status = 'CREATED';
                break;
            case self::REGISTERED:
                $status = 'REGISTERED';
                break;
            case self::READY:
                $status = 'READY';
                break;
            case self::BUSY:
                $status = 'BUSY';
                break;
            case self::CLOSED:
                $status = 'CLOSED';
                break;
            default:
                $status = 'INVALID';
        }

        return (string)\print_r([
            'status' => $status,
            'port' => $this->port,
            'pid' => $this->pid
        ], 1);
    }
}
