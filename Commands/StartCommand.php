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
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'The root of your appplication.', './')
            ->setDescription('Starts the server')
        ;
        
        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }
        $config = $this->loadConfig($input);

        if (file_exists($this->file)) {
            $modified = '';
            $fileConfig = json_decode(file_get_contents($this->file), true);
            if (json_encode($fileConfig) !== json_encode($config)) {
                $modified = ', modified by command arguments.';
            }
            $output->writeln(sprintf('<info>Read configuration %s%s.</info>', realpath($this->file), $modified));
        }
        $output->writeln(sprintf('<info>%s</info>', getcwd()));

        $this->renderConfig($output, $config);

        $handler = new ProcessManager($config['port'], $config['host'], $config['workers']);

        $handler->setBridge($config['bridge']);
        $handler->setAppEnv($config['app-env']);
        $handler->setDebug((boolean)$config['debug']);
        $handler->setLogging((boolean)$config['logging']);
        $handler->setAppBootstrap($config['bootstrap']);

        $handler->run();
    }
}
