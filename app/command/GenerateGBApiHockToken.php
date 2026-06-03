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
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $apiHookSecret = config('app.gb.api_hock_secret');
        $serverDomain = config('gb28181.server_domain');
        $token = hash_hmac('sha256', $serverDomain, $apiHookSecret);
        $output->writeln("Token: {$token}");
        $output->writeln("");
        $output->writeln("Update config/gb28181.php api.token with the value above.");
        $output->writeln("Also update .env API_HOOK_SECRET if using env-based config.");

        return self::SUCCESS;
    }

}
