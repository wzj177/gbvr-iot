<?php

namespace app\command;

use CoreW\Sdk\ZLMediaKit\MediaServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class ZlmStart extends Command
{
    protected static $defaultName = 'zlm:start';
    protected static $defaultDescription = 'zlm 服务器启动';

    /**
     * @return void
     */
    protected function configure(): void
    {
        // force 如果已经运行则强制kill后启动
        $this->addOption('force', '-f', InputOption::VALUE_NONE, '强制覆盖启动');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start zlm...');
        try {
            $maxRetry = 10;
            $mediaServer = new MediaServer(config('zlm'));
            if ($input->getOption('force')) {
                $output->writeln('kill zlm...');
                $mediaServer->stopWithPidFile();
                sleep(1);
            }

            $mediaServer->start();
            while (!$mediaServer->isRunning()) {
                if ($maxRetry-- <= 0) {
                    $output->writeln('zlm start failed');
                    return self::FAILURE;
                }
                sleep(1);
            }

            $output->writeln('zlm is running...');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
            return self::FAILURE;
        }
    }

}
