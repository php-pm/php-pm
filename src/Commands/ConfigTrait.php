<?php

namespace PHPPM\Commands;

use PHPPM\Configuration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

trait ConfigTrait
{
    protected $file = './ppm.json';

    protected function configurePPMOptions(Command $command)
    {
        $mapping = Configuration::getMapping();

        foreach ($mapping as $key => $parameters) {
            $command->addOption(
                $key,
                isset($parameters['shortcut']) ? $parameters['shortcut'] : null,
                InputOption::VALUE_REQUIRED,
                $parameters['description'],
                $parameters['default']
            );
        }
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

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Configuration
     * @throws \Exception
     */
    protected function loadConfig(InputInterface $input, OutputInterface $output)
    {
        if ($path = $this->getConfigPath($input)) {
            $config = Configuration::loadFromPath($path);
        } else {
            $config = new Configuration();
        }

        $arguments = [];
        $arguments['config'] = $path;

        foreach (Configuration::getMapping() as $key => $value) {
            if ($input->hasParameterOption('--' . $key)) {
                $arguments[$key] = $input->getOption($key);
            }
        }

        $config->setArguments($arguments);

        if (null === $config->getPhpCgiExecutable()) {
            $config->tryResolvePhpCgiPath();

            if (null === $config->getPhpCgiExecutable()) {
                $output->writeln('<error>PPM could not find a php-cgi path. Please specify by --cgi-path=</error>');
                exit(1);
            }
        }

        return $config;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $render
     * @return Configuration
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
            $this->renderConfig($output, $config->toArray());
        }
        return $config;
    }
}
