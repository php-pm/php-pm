<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigCommand extends Command
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('config')
            ->addOption('show-option', null, InputOption::VALUE_REQUIRED, 'Instead of writing the config, only show the given option.', '')
            ->setDescription('Configure config file, default - ppm.json');

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configPath = $this->getConfigPath($input, true);
        if (!$configPath) {
            $configPath = $this->file;
        }
        $config = $this->loadConfig($input, $output);

        if ($input->getOption('show-option')) {
            echo $config[$input->getOption('show-option')];
            exit(0);
        }

        $this->renderConfig($output, $config);

        $newContent = json_encode($config, JSON_PRETTY_PRINT);
        if (file_exists($configPath) && $newContent === file_get_contents($configPath)) {
            $output->writeln(sprintf('No changes to %s file.', realpath($configPath)));
            return null;
        }

        file_put_contents($configPath, $newContent);
        $output->writeln(sprintf('<info>%s file written.</info>', realpath($configPath)));

        return null;
    }
}
