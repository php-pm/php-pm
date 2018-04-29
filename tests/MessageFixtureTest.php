<?php

namespace PHPPM\Tests;

use PHPPM\ProcessManager;
use React\Socket\Connection;

class MessageFixtureTest extends PhpPmFixtureTestCase
{
    public function testStatusMessage()
    {
        $this->doSimpleMessageTest(
            '{"cmd": "status"}',
            '{"status":"healthy","workers":{"total":1,"ready":1,"busy":0,"created":0,"registered":0,"closed":0},"handled_requests":0,"handled_requests_per_worker":{"5501":0}}'
        );
    }

    public function testReloadMessage()
    {
        $this->doSimpleMessageTest(
            '{"cmd": "reload"}',
            '[]'
        );
    }

    public function testStopMessage()
    {
        $this->doSimpleMessageTest(
            '{"cmd": "stop"}',
            '[]'
        );
    }

    /**
     * Ensure unexpected worker ready messages close the connection
     */
    public function testUnexpectedReadyMessage()
    {
        $this->doManagerTest(function ($manager) {
            /** @var ProcessManager $manager */
            $connection = \Mockery::spy(Connection::class);
            $manager->processMessage($connection, '{"cmd": "ready"}');

            $connection->shouldHaveReceived('close');
        });
    }

    /**
     * Ensure unexpected worker registry messages close the connection
     */
    public function testUnexpectedWorkerRegistry()
    {
        $this->doManagerTest(function ($manager) {
            /** @var ProcessManager $manager */
            $connection = \Mockery::spy(Connection::class);
            $manager->processMessage($connection, '{"cmd": "register", "port": 5678, "pid": 9000}');

            $connection->shouldHaveReceived('close');
        });
    }
}
