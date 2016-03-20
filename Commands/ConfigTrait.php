<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

trait ConfigTrait
{
    protected $file = './ppm.json';

    protected function configurePPMOptions(\Symfony\Component\Console\Command\Command $command)
    {
        $command
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', 'HttpKernel')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer host. Default is 127.0.0.1', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', 8080)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', 8)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', 'dev')
            ->addOption('debug', null, InputOption::VALUE_OPTIONAL, 'Activates debugging so that your application is more verbose, enables also hot-code reloading. 1|0', 1)
            ->addOption('logging', null, InputOption::VALUE_OPTIONAL, 'Deactivates the http logging to stdout. 1|0', 1)
            ->addOption('max-requests', null, InputOption::VALUE_OPTIONAL, 'Max requests per work until it will be restarted', 1000)
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', 'PHPPM\Bootstraps\Symfony');
    }
    
    protected function renderConfig(OutputInterface $output, array $config)
    {
        $table = new Table($output);

        $rows = array_map(function ($a, $b) {
            return [$a, $b];
        }, array_keys($config), $config);
        $table->addRows($rows);

        $table->render();
    }

    protected function loadConfig(InputInterface $input)
    {
        $config = [];

        if (file_exists($this->file)) {
            $content = file_get_contents($this->file);
            $config = json_decode($content, true);
        }

        $config['bridge'] = $this->optionOrConfigValue($input, 'bridge', $config);
        $config['host'] = $this->optionOrConfigValue($input, 'host', $config);
        $config['port'] = (int)$this->optionOrConfigValue($input, 'port', $config);
        $config['workers'] = (int)$this->optionOrConfigValue($input, 'workers', $config);
        $config['app-env'] = $this->optionOrConfigValue($input, 'app-env', $config);
        $config['debug'] = $this->optionOrConfigValue($input, 'debug', $config);
        $config['logging'] = $this->optionOrConfigValue($input, 'logging', $config);
        $config['bootstrap'] = $this->optionOrConfigValue($input, 'bootstrap', $config);
        $config['max-requests'] = (int)$this->optionOrConfigValue($input, 'max-requests', $config);

        return $config;
    }

    protected function optionOrConfigValue(InputInterface $input, $name, $config)
    {
        if ($input->hasParameterOption('--' . $name)) {
            return $input->getOption($name);
        }

        return isset($config[$name]) ? $config[$name] : $input->getOption($name);
    }
}