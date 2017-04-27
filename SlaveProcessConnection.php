<?php
declare(ticks = 1);

namespace PHPPM;

use React\Socket\Connection;

class SlaveProcessConnection
{
    /**
     * @var string
     */
    protected $host = null;

    /**
     * @var int
     */
    protected $port = null;

    /**
     * @var bool
     */
    protected $ready = false;

    /**
     * @var bool
     */
    protected $busy = false;

    /**
     * @var bool
     */
    protected $closeWhenFree = false;

    /**
     * @var bool
     */
    protected $waitForRegister = true;

    /**
     * @var bool
     */
    protected $duringBootstrap = false;

    /**
     * @var bool
     */
    protected $bootstrapFailed = 0;

    /**
     * @var bool
     */
    protected $keepClosed = false;

    /**
     * @var int
     */
    protected $requests = 0;

    /**
     * @var int
     */
    protected $connections = 0;

    /**
     * @var int
     */
    protected $pid = null;

    /**
     * @var resource
     */
    protected $stderr = null;

    /**
     * @var ressource
     */
    protected $connection = null;

    /**
     * SlaveProxy constructor
     *
     * @param string          $host
     * @param int             $port
     */
    public function __construct($host, $port, $bootstrapFailed = 0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->bootstrapFailed = $bootstrapFailed;
    }

    /**
     * Slave connection established, update status
     *
     * @param int $pid slave process id
     * @param resource $conn slave connection
     */
    public function registerConnection($pid, $conn)
    {
        $this->pid = $pid;
        $this->connection = $conn;

        $this->ready = false;
        $this->waitForRegister = false;
        $this->duringBootstrap = true;
    }

    public function connect()
    {
        $this->setBusy();
        $this->connections++;
    }

    public function disconnect($handled = false)
    {
        $this->setBusy(false);
        $this->connections--;

        if ($handled) {
            $this->requests++;
        }
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     */
    public function terminate($graceful = false)
    {
        $this->ready = false;
        if (isset($this->stderr)) {
            $this->stderr->close();
            $this->stderr = null;
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process, $graceful ? null : SIGKILL);
        }

        if ($this->pid) {
            //make sure its dead
            posix_kill($this->pid, SIGKILL);
        }
    }

    /**
     * @return bool $ready
     */
    public function getReady()
    {
        return $this->ready;
    }

    /**
     * @param bool $ready
     */
    public function setReady($ready = true)
    {
        $this->ready = $ready;

        if ($ready) {
            $this->bootstrapFailed = 0;
            $this->duringBootstrap = false;
        }
    }

    /**
     * Mark slave for restart
     */
    public function prepareForRestart()
    {
        $this->keepClosed = false;
        $this->bootstrapFailed = 0;
        $this->duringBootstrap = false;
    }

    /**
     * @return bool $busy
     */
    public function getBusy()
    {
        return $this->busy;
    }

    /**
     * @param bool $busy
     */
    public function setBusy($busy = true)
    {
        $this->busy = $busy;
    }

    /**
     * @return int $connections
     */
    public function getConnections()
    {
        return $this->connections;
    }

    /**
     * @return string $connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Close slave connection or mark for closing
     *
     * @return bool connection was closed
     */
    public function closeConnection()
    {
        if ($this->connection && $this->closeWhenFree) {
            $this->connection->close();
            $this->connection = null;
        }

        if ($this->connection && $this->connection->isWritable()) {
            if ($this->busy) {
                $this->closeWhenFree = true;
            } else {
                $this->connection->close();
                $this->connection = null;
            }
        }
    }

    /**
     * Stop slave from accepting more connections
     */
    public function seal()
    {
        $this->keepClosed = true;

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * Associate slave with process and stderr
     *
     * @param resource $process
     * @param resource $stderr
     */
    public function attach($process, $stderr = null)
    {
        $this->process = $process;
        $this->stderr = $stderr;
    }

    /**
     * @return string $host
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return int $port
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @return int $requests
     */
    public function getRequestsHandled()
    {
        return $this->requests;
    }

    /**
     * @return int
     */
    public function getBootstrapFailed()
    {
        return $this->bootstrapFailed;
    }

    /**
     * @return int
     */
    public function bootstrapFailed()
    {
        return ++$this->bootstrapFailed;
    }

    /**
     * @return bool
     */
    public function getDuringBootstrap()
    {
        return $this->duringBootstrap;
    }

    /**
     * @return bool
     */
    public function getKeepClosed()
    {
        return $this->keepClosed;
    }

    /**
     * @return bool
     */
    public function getWaitForRegister()
    {
        return $this->waitForRegister;
    }
}
