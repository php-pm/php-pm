<?php

namespace PHPPM\Commands;

use PHPPM\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    use ConfigTrait;

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
        $config = $this->initializeConfig($input, $output, false);

        $handler = new Client();
        $handler->setSocketPath($config['socket-path']);
        $handler->getStatus(function($status) use ($output) {
            foreach ($status as $key => $value) {
                $output->writeln(sprintf('%s: %s', $key, $value));
            }
        });
    }

}