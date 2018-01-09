<?php

namespace PHPPM;

use React\Socket\ConnectionInterface;

/**
 * SlavePool singleton is responsible for maintaining a pool of slave instances
 */
class SlavePool
{
    /** @var Slave[] */
    private $slaves = [];

    /**
     * Add slave to pool
     *
     * Slave is in CREATED state
     *
     * @param int $port
     * @return void
     */
    public function add(Slave $slave)
    {
        $port = $slave->getPort();

        if (isset($this->slaves[$port])) {
            throw new \Exception("Slave port $port already occupied.");
        }

        if ($slave->getPort() !== $port) {
            throw new \Exception("Slave mis-assigned.");
        }

        $this->slaves[$port] = $slave;
    }

    /**
     * Remove from pool
     *
     * @param int $port
     */
    public function remove(Slave $slave)
    {
        $port = $slave->getPort();

        // validate existance
        $this->getByPort($port);

        // remove
        unset($this->slaves[$port]);
    }

    /**
     * Get slave by port
     *
     * @param int $port
     * @return Slave
     */
    public function getByPort($port)
    {
        if (!isset($this->slaves[$port])) {
            throw new \Exception("Slave port $port empty.");
        }

        return $this->slaves[$port];
    }

    /**
     * Get slave slaves by connection
     *
     * @throws \Exception
     */
    public function getByConnection(ConnectionInterface $connection)
    {
        $hash = spl_object_hash($connection);

        foreach ($this->slaves as $slave) {
            if ($hash === spl_object_hash($slave->getConnection())) {
                return $slave;
            }
        }

        throw new \Exception("Slave connection not registered.");
    }

    /**
     * Get multiple slaves by status
     */
    public function getByStatus($status)
    {
        return array_filter($this->slaves, function($slave) use ($status) {
            return $status === Slave::ANY || $status === $slave->getStatus();
        });
    }
}
