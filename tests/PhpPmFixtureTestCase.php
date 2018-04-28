<?php

namespace PHPPM\Tests;

use PHPPM\Configuration;
use PHPPM\ProcessClient;
use PHPPM\ProcessManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;

abstract class PhpPmFixtureTestCase extends TestCase
{
    /**
     * @var Output
     */
    protected $output;

    public function setUp()
    {
        $this->output = new ConsoleOutput();
    }

    public function getConfiguration($path = './tests/fixtures/ppm.json')
    {
        $config = Configuration::loadFromPath($path);
        $config->setArguments(
            [
                'config' => $path,
                'socket-path' => sprintf('.ppm/%s/', $this->getName()),
                'pidfile' => sprintf('.ppm/%s.pid', $this->getName())
            ]
        );

        $this->assertEquals(sprintf('.ppm/%s/', $this->getName()), $config->getSocketPath());
        $config->tryResolvePhpCgiPath();
        return $config;
    }

    /**
     * @param ProcessManager $manager
     * @return ProcessClient
     */
    public function getProcessClient($manager)
    {
        $config = $manager->getConfig();

        $handler = new ProcessClient();
        $handler->setSocketPath($config->getSocketPath());
        return $handler;
    }

    public function doManagerTest($callback)
    {
        $config = $this->getConfiguration();

        $manager = new MockProcessManager($this->output, $config);

        $manager->once('ready', function () use ($callback, $manager) {
            // realistically this is probably only going to happen if the test is doing too much with async
            $manager->getLoop()->addTimer(1, function () use ($manager) {
                $this->output->writeln("Test took too long, quitting.");
                $manager->shutdown(false);
                $this->markAsRisky();
            });

            try {
                $this->output->writeln('<info>Bootstrap complete, running fixture test...</info>');
                $callback($manager);
            } catch (\Exception $ex) {
                throw $ex;
            } finally {
                $manager->shutdown(false);
            }
        });

        $manager->run();
    }

    /**
     * Helper method to override a PM config
     *
     * @param ProcessManager $manager
     * @param array $patch
     */
    public function updateManagerConfig($manager, $patch)
    {
        $config = $manager->getConfig();
        $config->setArguments(array_merge($config->getArguments(), $patch));
    }
}
