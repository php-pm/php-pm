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
            ->addOption('port', null, InputOption::VALUE_OPTIONAL)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL)
            ->setDescription('Starts the server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        $bridge  = $input->getOption('bridge');

        $port    = null !== $input->getOption('port') ? (int) $input->getOption('port') : 8080;
        $workers = null !== $input->getOption('workers') ? (int) $input->getOption('workers') : 8;
        $appenv  = null !== $input->getOption('app-env') ? $input->getOption('app-env') : 'prod';

        $handler = new ProcessManager($port, $workers);
        $handler->setBridge($bridge);
        $handler->setAppEnv($appenv);
        $handler->run();
    }

}
