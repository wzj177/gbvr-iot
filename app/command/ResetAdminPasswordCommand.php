<?php

namespace app\command;

use CoreW\Core;
use CoreW\Business\User\Service\UserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ResetAdminPasswordCommand extends Command
{
    protected static $defaultName = 'admin:reset-password';
    protected static $defaultDescription = '重置管理员密码';

    const DEFAULT_PASSWORD = 'qwe123456@vr';

    protected function configure()
    {
        $this->addArgument('email', InputArgument::OPTIONAL, '管理员邮箱', 'superAdmin@vr.net')
            ->addArgument('password', InputArgument::OPTIONAL, '新密码', self::DEFAULT_PASSWORD);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        $userService = $this->getUserService();
        $user = $userService->getUserByEmail($email);

        if (empty($user)) {
            $output->writeln("<error>找不到邮箱为 {$email} 的用户</error>");
            return self::FAILURE;
        }

        $userService->changePassword($user['id'], $password, '127.0.0.1');

        $output->writeln("<info>密码重置成功</info>");
        $output->writeln("  邮箱: {$email}");
        $output->writeln("  新密码: {$password}");

        return self::SUCCESS;
    }

    private function getUserService(): UserService
    {
        return Core::initCiBiz()->service('User:UserService');
    }
}
