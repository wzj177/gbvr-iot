<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class GenerateGBApiHockToken extends Command
{
    protected static $defaultName = 'gb:generate-hock-token';
    protected static $defaultDescription = '生成api hock token';

    /**
     * @return void
     */
    protected function configure()
    {
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apiHookSecret = config('app.gb.api_hock_secret');
        $serverDomain = config('gb28181.server_domain');
        $token = password_hash($apiHookSecret . $serverDomain, PASSWORD_DEFAULT);
        $output->writeln("Token: {$token}");

        return self::SUCCESS;
    }

}
