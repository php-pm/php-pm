<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('start')
            ->addArgument('working-directory', null, 'working directory', './')
            ->addOption('bridge', null, InputOption::VALUE_REQUIRED)
            ->setDescription('Starts the server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        $bridge = $input->getOption('bridge');

        $handler = new ProcessManager();
        $handler->setBridge($bridge);
        $handler->run();
    }

}