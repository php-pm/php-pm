<?php

namespace PHPPM\Tests;

use PHPPM\Configuration;

class ConfigurationTest extends PhpPmTestCase
{
    public function testDefaults()
    {
        $config = new Configuration([]);
        $this->assertEquals(8080, $config->getPort());
        $this->assertEquals('127.0.0.1', $config->getHost());
        $this->assertEquals(30, $config->getReloadTimeout());
        $this->assertEquals(8, $config->getSlaveCount());
        $this->assertEquals(1000, $config->getMaxRequests());
        $this->assertEquals('PHPPM\Bootstraps\Symfony', $config->getAppBootstrap());
        $this->assertEquals('.ppm/ppm.pid', $config->getPIDFile());
    }

    public function testAttributeOverridesDefault()
    {
        $config = new Configuration([
            'host' => '0.0.0.0'
        ]);

        $this->assertEquals('0.0.0.0', $config->getHost());
    }

    public function testArgumentOverridesAttribute()
    {
        $config = new Configuration([
            'host' => '0.0.0.0'
        ]);

        $config->setArguments(['host' => '8.8.8.8']);
        $this->assertEquals('8.8.8.8', $config->getHost());
        $config->setArguments([]);
        $this->assertEquals('0.0.0.0', $config->getHost());
    }

    public function testAttributeCasts()
    {
        $config = new Configuration([
            'max-requests' => '1000',
            'debug' => 1,
            'logging' => 0
        ]);

        $this->assertInternalType('int', $config->getMaxRequests());
        $this->assertEquals(1000, $config->getMaxRequests());
        $this->assertInternalType('bool', $config->isDebug());
        $this->assertTrue($config->isDebug());
        $this->assertInternalType('bool', $config->isLogging());
        $this->assertNotTrue($config->isLogging());
    }
}
