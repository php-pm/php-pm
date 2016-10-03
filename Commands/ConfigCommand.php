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
            ->setDescription('Configure ppm.json in current folder');

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->loadConfig($input, $output);

        $this->renderConfig($output, $config);

        $newContent = json_encode($config, JSON_PRETTY_PRINT);
        if (file_exists($this->file) && $newContent === file_get_contents($this->file)) {
            $output->writeln(sprintf('No changes to %s file.', realpath($this->file)));
            return;
        }

        file_put_contents($this->file, $newContent);
        $output->writeln(sprintf('<info>%s file written.</info>', realpath($this->file)));
    }
}
