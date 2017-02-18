<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;

trait ConfigTrait
{
    protected $file = './ppm.json';

    protected function configurePPMOptions(Command $command)
    {
        $command
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', 'HttpKernel')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer host. Default is 127.0.0.1', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', 8080)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', 8)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', 'dev')
            ->addOption('debug', null, InputOption::VALUE_OPTIONAL, 'Enable/Disable debugging so that your application is more verbose, enables also hot-code reloading. 1|0', 1)
            ->addOption('logging', null, InputOption::VALUE_OPTIONAL, 'Enable/Disable http logging to stdout. 1|0', 1)
            ->addOption('static', null, InputOption::VALUE_OPTIONAL, 'Enable/Disable static file serving. 1|0', 1)
            ->addOption('max-requests', null, InputOption::VALUE_OPTIONAL, 'Max requests per worker until it will be restarted', 1000)
            ->addOption('concurrent-requests', null, InputOption::VALUE_OPTIONAL, 'If a worker is allowed to handle more than one request at the same time. This can lead to issues when the application does not support it but makes it faster. (like when they operate on globals at the same time) 1|0', 0)
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', 'PHPPM\Bootstraps\Symfony')
            ->addOption('cgi-path', null, InputOption::VALUE_OPTIONAL, 'Full path to the php-cgi executable', false)
            ->addOption('socket-path', null, InputOption::VALUE_OPTIONAL, 'Path to a folder where socket files will be placed. Relative to working-directory or cwd()', '.ppm/run/')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config file', '');
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

    /**
     * @param InputInterface $input
     * @param bool $create
     * @return string
     * @throws \Exception
     */
    protected function getConfigPath(InputInterface $input, $create = false)
    {
        $configOption = $input->getOption('config');
        if ($configOption && !file_exists($configOption)) {
            if ($create) {
                file_put_contents($configOption, json_encode([]));
            } else {
                throw new \Exception(sprintf('Config file not found: "%s"', $configOption));
            }
        }
        $possiblePaths = [
            $configOption,
            $this->file,
            sprintf('%s/%s', dirname($GLOBALS['argv'][0]), $this->file)
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }
        return '';
    }

    protected function loadConfig(InputInterface $input, OutputInterface $output)
    {
        $config = [];

        if ($path = $this->getConfigPath($input)) {
            $content = file_get_contents($path);
            $config = json_decode($content, true);
        }

        $config['bridge'] = $this->optionOrConfigValue($input, 'bridge', $config);
        $config['host'] = $this->optionOrConfigValue($input, 'host', $config);
        $config['port'] = (int)$this->optionOrConfigValue($input, 'port', $config);
        $config['workers'] = (int)$this->optionOrConfigValue($input, 'workers', $config);
        $config['app-env'] = $this->optionOrConfigValue($input, 'app-env', $config);
        $config['debug'] = $this->optionOrConfigValue($input, 'debug', $config);
        $config['logging'] = $this->optionOrConfigValue($input, 'logging', $config);
        $config['static'] = (boolean)$this->optionOrConfigValue($input, 'static', $config);
        $config['bootstrap'] = $this->optionOrConfigValue($input, 'bootstrap', $config);
        $config['max-requests'] = (int)$this->optionOrConfigValue($input, 'max-requests', $config);
        $config['concurrent-requests'] = (boolean)$this->optionOrConfigValue($input, 'concurrent-requests', $config);
        $config['socket-path'] = $this->optionOrConfigValue($input, 'socket-path', $config);

        $config['cgi-path'] = $this->optionOrConfigValue($input, 'cgi-path', $config);

        if (false === $config['cgi-path']) {
            //not set in config nor in command options -> autodetect path
            $executableFinder = new PhpExecutableFinder();
            $binary = $executableFinder->find();

            $cgiPaths = [
                $binary . '-cgi', //php7.0 -> php7.0-cgi
                str_replace('php', 'php-cgi', $binary), //php7.0 => php-cgi7.0
            ];

            foreach ($cgiPaths as $cgiPath) {
                $path = trim(`which $cgiPath`);
                if ($path) {
                    $config['cgi-path'] = $path;
                    break;
                }
            }

            if (false === $config['cgi-path']) {
                $output->writeln('<error>PPM could find a php-cgi path. Please specify by --cgi-path=</error>');
                exit(1);
            }
        }

        return $config;
    }

    protected function optionOrConfigValue(InputInterface $input, $name, $config)
    {
        if ($input->hasParameterOption('--' . $name)) {
            return $input->getOption($name);
        }

        return isset($config[$name]) ? $config[$name] : $input->getOption($name);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $render
     * @return array|mixed
     */
    protected function initializeConfig(InputInterface $input, OutputInterface $output, $render = true)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }
        $config = $this->loadConfig($input, $output);

        if ($path = $this->getConfigPath($input)) {
            $modified = '';
            $fileConfig = json_decode(file_get_contents($path), true);
            if (json_encode($fileConfig) !== json_encode($config)) {
                $modified = ', modified by command arguments';
            }
            $output->writeln(sprintf('<info>Read configuration %s%s.</info>', $path, $modified));
        }
        $output->writeln(sprintf('<info>%s</info>', getcwd()));

        if ($render) {
            $this->renderConfig($output, $config);
        }
        return $config;
    }
}
