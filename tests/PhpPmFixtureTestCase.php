<?php

namespace PHPPM\Tests;

use Mockery\Exception\InvalidCountException;
use Mockery\Mock;
use PHPPM\Configuration;
use PHPPM\ProcessManager;
use PHPUnit\Framework\TestCase;
use React\Socket\Connection;
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

    public function tearDown()
    {
        \Mockery::close();
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
     * Do a message test. Sends a message to the process manager, validates expected output.
     *
     * If this fails because of method end(<Any Arguments>) not being called: there must be a problem with how the
     * process manager is handling the connection.
     *
     * If this fails because of method end(<anything else>) not being called: there is a mismatch with the expected
     * output. Unfortunately we can't print out the contents to compare easily (although in theory we can with
     * ReflectionClass trickery), so you'll want to use a debugger to inspect the returned message.
     *
     * @param string $input
     * @param string $expected
     */
    protected function doSimpleMessageTest($input, $expected = null)
    {
        $this->doManagerTest(function ($manager) use ($input, $expected) {
            /** @var ProcessManager $manager */
            $connection = \Mockery::spy(Connection::class);
            $manager->processMessage($connection, $input);

            $connection->shouldHaveReceived('end');

            if (null !== $expected) {
                $connection->shouldHaveReceived('end', [$expected]);
            }
        });
    }

    /**
     * Returns a partially mocked version of the ProcessManager; this doesn't bind to web.
     *
     * @return ProcessManager|Mock
     */
    public function getMockedManager()
    {
        $config = $this->getConfiguration();

        $manager = \Mockery::mock(ProcessManager::class . '[startListening]', [$this->output, $config])
            ->shouldAllowMockingProtectedMethods();

        $manager->shouldReceive('startListening')->andReturn(null);

        return $manager;
    }

    public function doManagerTest(callable $callback)
    {
        $manager = $this->getMockedManager();

        $manager->once('ready', function () use ($callback, $manager) {
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
