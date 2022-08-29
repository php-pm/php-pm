<?php

namespace PHPPM\Tests;

use PHPPM\Slave;
use PHPPM\SlavePool;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SlavePoolTest extends PhpPmTestCase
{
    public function testShouldNotSetTimerWithoutTtl()
    {
        $loop = $this->createMock(LoopInterface::class);

        $loop->expects(self::never())->method('addTimer');

        $slavePool = new SlavePool(
            $loop,
            $this->createMock(OutputInterface::class),
        );

        $slavePool->add($this->createMock(Slave::class));
    }

    /**
     * @dataProvider ttlDataProvider
     */
    public function testShouldSetRestartTimerWhenTtlWasSet($ttl, $min, $max)
    {
        $loop = $this->createMock(LoopInterface::class);

        $loop
            ->expects(self::once())
            ->method('addTimer')
            ->with(self::logicalAnd(
                self::greaterThanOrEqual($min),
                self::lessThanOrEqual($max)
            ));

        $slavePool = new SlavePool(
            $loop,
            $this->createMock(OutputInterface::class),
        );

        $slavePool->add($this->createConfiguredMock(Slave::class, [
            'getTtl' => $ttl
        ]));
    }

    public function ttlDataProvider()
    {
        return [
            'small ttl' => [5, 5, 10],
            'bigger ttl' => [60, 55, 65]
        ];
    }

    public function testShouldCancelTimerOnSlaveRemoval()
    {
        $loop = $this->createMock(LoopInterface::class);
        $timer = $this->createMock(TimerInterface::class);

        $loop
            ->method('addTimer')
            ->willReturn($timer);

        $loop
            ->expects(self::once())
            ->method('cancelTimer')
            ->with(self::equalTo($timer));

        $slavePool = new SlavePool(
            $loop,
            $this->createMock(OutputInterface::class)
        );

        $slave = $this->createConfiguredMock(Slave::class, [
            'getTtl' => 20,
            'getPort' => 5001
        ]);

        $slavePool->add($slave);
        $slavePool->remove($slave);
    }

    public function testShouldFindOneSlaveWithReadyState()
    {
        $slavePool = new SlavePool(
            $this->createMock(LoopInterface::class),
            $this->createMock(OutputInterface::class),
        );

        $busySlave = $this->createConfiguredMock(Slave::class, [
            'getPort' => 5001,
            'getStatus' => Slave::BUSY,
        ]);
        $readySlave = $this->createConfiguredMock(Slave::class, [
            'getPort' => 5002,
            'getStatus' => Slave::READY,
        ]);

        $slavePool->add($busySlave);
        $slavePool->add($readySlave);

        self::assertSame($readySlave, $slavePool->findReadySlave());
    }

    public function testShouldReturnNullWhenNoReadySlaveFound()
    {
        $slavePool = new SlavePool(
            $this->createMock(LoopInterface::class),
            $this->createMock(OutputInterface::class),
        );

        $busySlave = $this->createConfiguredMock(Slave::class, [
            'getPort' => 5001,
            'getStatus' => Slave::BUSY,
        ]);

        $slavePool->add($busySlave);

        self::assertNull($slavePool->findReadySlave());
    }
}
