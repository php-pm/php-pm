<?php

namespace PHPPM\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
            ->setDescription('Configure config file, default - ppm.json');

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->loadConfig($input, $output);
        // to be sure we have valid config path
        $configPath = $this->getConfig($input);

        $this->renderConfig($output, $config);

        $newContent = json_encode($config, JSON_PRETTY_PRINT);
        if (file_exists($configPath) && $newContent === file_get_contents($configPath)) {
            $output->writeln(sprintf('No changes to %s file.', realpath($configPath)));
            return;
        }

        file_put_contents($configPath, $newContent);
        $output->writeln(sprintf('<info>%s file written.</info>', realpath($configPath)));
    }

    /**
     * @param InputInterface $input
     * @return string
     */
    private function getConfig(InputInterface $input)
    {
        $configPath = $this->getConfigPath($input);
        if (is_null($configPath)) {
            $configPath = $input->getOption('config') ?: $this->file;
        }
        return $configPath;
    }
}
