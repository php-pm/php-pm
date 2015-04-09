<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $config = [];
        if (file_exists($file = './ppm.json') || file_exists($file = dirname(realpath($GLOBALS['argv'][0])) . DIRECTORY_SEPARATOR . 'ppm.json')) {
             $config = json_decode(file_get_contents($file), true);
        }

        $bridge        = $this->defaultOrConfig($config, 'bridge', 'HttpKernel');
        $host          = $this->defaultOrConfig($config, 'host', '127.0.0.1');
        $port          = (int) $this->defaultOrConfig($config, 'port', 8080);
        $workers       = (int) $this->defaultOrConfig($config, 'workers', 8);
        $appenv        = $this->defaultOrConfig($config, 'app-env', 'dev');
        $appBootstrap  = $this->defaultOrConfig($config, 'bootstrap', 'PHPPM\Bootstraps\Symfony');

        $this
            ->setName('start')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'The root of your appplication.', './')
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', $bridge)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer host. Default is 127.0.0.1', $host)
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', $port)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', $workers)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', $appenv)
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', $appBootstrap)
            ->setDescription('Starts the server')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        $bridge        = $input->getOption('bridge');
        $host          = $input->getOption('host');
        $port          = (int) $input->getOption('port');
        $workers       = (int) $input->getOption('workers');
        $appenv        = $input->getOption('app-env');
        $appBootstrap  = $input->getOption('bootstrap');

        $handler = new ProcessManager($port, $host, $workers);

        $handler->setBridge($bridge);
        $handler->setAppEnv($appenv);
        $handler->setAppBootstrap($appBootstrap);

        $handler->run();
    }

    private function defaultOrConfig($config, $name, $default) {
        $val = $default;

        if (isset($config[$name])) {
            $val = $config[$name];
        }

        return $val;
    }
}
