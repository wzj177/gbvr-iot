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
    protected static $defaultDescription = 'zlm start';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name description');
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
