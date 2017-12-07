<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('start')
            ->setDescription('Starts the server')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'Working directory', './')
        ;

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->initializeConfig($input, $output);

        $class = isset($config['processmanager']) ? $config['processmanager'] : ProcessManager::class;
        $handler = new $class($output, $config['port'], $config['host'], $config['workers']);

        $handler->setBridge($config['bridge']);
        $handler->setAppEnv($config['app-env']);
        $handler->setDebug((boolean)$config['debug']);
        $handler->setLogging((boolean)$config['logging']);
        $handler->setAppBootstrap($config['bootstrap']);
        $handler->setMaxRequests($config['max-requests']);
        $handler->setPhpCgiExecutable($config['cgi-path']);
        $handler->setSocketPath($config['socket-path']);
        $handler->setPIDFile($config['pidfile']);
        $handler->setPopulateServer($config['populate-server-var']);
        $handler->setStaticDirectory($config['static-directory']);
        $handler->run();
    }
}
