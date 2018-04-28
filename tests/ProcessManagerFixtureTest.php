<?php

namespace PHPPM\Tests;

use PHPPM\ProcessManager;
use PHPPM\Slave;

class ProcessManagerFixtureTest extends PhpPmFixtureTestCase
{
    public function testWorkerCountReload()
    {
        $this->doManagerTest(function ($manager) {
            /** @var ProcessManager $manager */
            $this->assertEquals(1, count($manager->getSlavePool()->getByStatus(Slave::ANY)));
            $this->updateManagerConfig($manager, ['workers' => 2]);
            $manager->reload();
            $this->assertEquals(2, count($manager->getSlavePool()->getByStatus(Slave::ANY)));
            $this->updateManagerConfig($manager, ['workers' => 1]);
            $manager->reload();
            $this->assertEquals(1, count($manager->getSlavePool()->getByStatus(Slave::ANY)));
        });
    }
}
