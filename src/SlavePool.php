<?php

namespace PHPPM;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SlavePool singleton is responsible for maintaining a pool of slave instances
 */
class SlavePool
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var TimerInterface[]
     */
    private $restartTimers = [];
    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(LoopInterface $loop, OutputInterface $output)
    {
        $this->loop = $loop;
        $this->output = $output;
    }

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

        $this->setTtlTimer($slave);
    }

    private function setTtlTimer(Slave $slave)
    {
        if (null === $slave->getTtl()) {
            return;
        }

        // naive way of handling restarts not at the same time due to ttl expiration
        $interval = $slave->getTtl() > 10 ? $slave->getTtl() + random_int(-5, 5) : $slave->getTtl() + random_int(0, 5);
        $this->restartTimers[$slave->getPort()] = $this->loop->addTimer($interval, function () use ($slave) {
            unset($this->restartTimers[$slave->getPort()]);
            $this->restartSlave($slave);
        });
    }

    private function restartSlave(Slave $slave)
    {
        if (\in_array($slave->getStatus(), [Slave::LOCKED, Slave::CLOSED], true)) {
            return;
        }

        if ($slave->getStatus() !== Slave::READY) {
            $this->loop->futureTick(function () use ($slave) {
                $this->restartSlave($slave);
            });

            return;
        }

        $slave->close();
        $this->output->writeln(sprintf('Restart worker #%d because it reached its TTL', $slave->getPort()));
        $slave->getConnection()->close();
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
        if (\array_key_exists($port, $this->restartTimers)) {
            $this->loop->cancelTimer($this->restartTimers[$port]);
            unset($this->restartTimers[$port]);
        }
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
        return array_filter($this->slaves, static function ($slave) use ($status) {
            return $status === Slave::ANY || $status === $slave->getStatus();
        });
    }

    /**
     * Find one slave with READY state
     *
     * @return Slave|null
     */
    public function findReadySlave()
    {
        $slaves = $this->getByStatus(Slave::READY);

        return \count($slaves) > 0 ? array_shift($slaves) : null;
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
            return \count($this->getByStatus($state));
        }, $map);
    }
}
