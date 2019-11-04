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
     * Remove from pool
     *
     * @param Slave $slave
     *
     * @return void
     */
    public function remove(Slave $slave)
    {
        $port = $slave->getPort();

        // validate existence
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
     * @param ConnectionInterface $connection
     *
     * @return Slave
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
        return array_filter($this->slaves, function (Slave $slave) use ($status) {
            return $status === Slave::ANY || $status === $slave->getStatus();
        });
    }

    /**
     * @param $refreshInterval
     * @return Slave[]
     */
    public function findSlavesToBeRefreshed($refreshInterval) : array
    {
        /** @var Slave[] $slaves */
        $slaves = $this->getByStatus(Slave::READY);

        // Do not refresh more than a quarter of available slaves
        $max_count = (int) ceil(count($slaves) / 4);

        // Sort so oldest ones gets preferred
        usort($slaves, function (Slave $a, Slave $b) {
            return $a->getRefreshedAt() <=> $b->getRefreshedAt();
        });

        $refreshableSlaves = [];

        foreach ($slaves as $slave) {
            if ($slave->shouldRefresh($refreshInterval)) {
                $refreshableSlaves[] = $slave;
            }

            if (0 === --$max_count) {
                break;
            }
        }

        return $refreshableSlaves;
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
