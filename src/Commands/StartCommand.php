<?php

namespace PHPPM\Commands;

use PHPPM\Configuration;
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
        $class = $config->getProcessManager();

        /** @var ProcessManager $handler */
        $handler = new $class($output, $config);
        $handler->run();

        return null;
    }
}
