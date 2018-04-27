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
     * @param Slave $slave
     *
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
     * Remove a slave from the pool.
     *
     * @param Slave $slave
     * @throws \Exception If attempted to remove a slave from a pool it is not in.
     */
    public function remove(Slave $slave)
    {
        $port = $slave->getPort();

        // validate existence
        if ($this->getByPort($port) === null) {
            throw new \Exception("Slave port $port empty.");
        }

        // remove
        unset($this->slaves[$port]);
    }

    /**
     * Get slave by port. Returns null if the port is not being used by a slave in this pool.
     *
     * @param int $port
     * @return Slave|null
     */
    public function getByPort($port)
    {
        return isset($this->slaves[$port]) ? $this->slaves[$port] : null;
    }

    /**
     * Get slave slaves by connection
     *
     * @param ConnectionInterface $connection
     *
     * @return mixed
     * @throws \Exception
     */
    public function getByConnection(ConnectionInterface $connection)
    {
        $hash = spl_object_hash($connection);

        foreach ($this->slaves as $slave) {
            if ($slave->getConnection() && $hash === spl_object_hash($slave->getConnection())) {
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
        return array_filter($this->slaves, function ($slave) use ($status) {
            return $status === Slave::ANY || $status === $slave->getStatus();
        });
    }

    /**
     * Return a human-readable summary of the slaves in the pool.
     *
     * @return array
     */
    public function getStatusSummary()
    {
        $map = [
            'total' => Slave::ANY,
            'ready' => Slave::READY,
            'busy' => Slave::BUSY,
            'created' => Slave::CREATED,
            'registered' => Slave::REGISTERED,
            'closed' => Slave::CLOSED
        ];

        return array_map(function ($state) {
            return count($this->getByStatus($state));
        }, $map);
    }
}
