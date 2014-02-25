<?php

namespace PHPPM\Commands;

use PHPPM\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('status')
            ->addArgument('working-directory', null, 'working directory', './')
            ->setDescription('Status of all processes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handler = new Client();
        $handler->getStatus(function($status) {
            var_dump($status);
        });
    }

}