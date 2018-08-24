<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    use ConfigTrait;
    use DaemonTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('start')
            ->setDescription('Starts the server')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'Working directory', './');

        $this->configureStartupAndAccessOptions($this);
        $this->configureConfigOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('daemonize')) {
            $isChild = $this->daemonize();
            if (!$isChild) {
                if ($output->isVerbose()) {
                    $output->writeln('Daemonized');
                }
                return null;
            }

            // children shouldn't hold onto parents input/output
            $input  = $this->recreateInput($input);
            $output = $this->recreateOutput($output, $input->getOption('logfile'));
        }

        $config = $this->initializeConfig($input, $output);

        $handler = new ProcessManager($output, $config['port'], $config['host'], $config['workers']);

        $handler->setBridge($config['bridge']);
        $handler->setAppEnv($config['app-env']);
        $handler->setDebug((boolean)$config['debug']);
        $handler->setReloadTimeout((int)$config['reload-timeout']);
        $handler->setLogging((boolean)$config['logging']);
        $handler->setAppBootstrap($config['bootstrap']);
        $handler->setMaxRequests($config['max-requests']);
        $handler->setMaxExecutionTime($config['max-execution-time']);
        $handler->setMemoryLimit($config['memory-limit']);
        $handler->setTtl($config['ttl']);
        $handler->setPhpCgiExecutable($config['cgi-path']);
        $handler->setSocketPath($config['socket-path']);
        $handler->setPIDFile($config['pidfile']);
        $handler->setPopulateServer($config['populate-server-var']);
        $handler->setStaticDirectory($config['static-directory']);
        $handler->run();

        return null;
    }
}
