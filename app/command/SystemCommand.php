<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Business\User\CurrentUser;
use CoreW\Business\User\Service\UserService;
use CoreW\Core;
use CoreW\Traits\UserSessionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class SystemCommand extends Command
{
    use UserSessionTrait;

    protected static $defaultName = 'system:init';
    protected static $defaultDescription = '系统初始化';

    const DEFAULT_USER_PWD = 'qwe123456@vr';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('nickname', InputArgument::OPTIONAL, '初始账号的nickname')
            ->addArgument('password', InputArgument::OPTIONAL, '初始账号的password')
            ->addArgument('email', InputArgument::OPTIONAL, '初始账号的email');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>开始初始化系统</info>');
        try {
            $adminUser = $this->makeAdminUser($input);
            $user = $this->initAdminUser($adminUser, $output);
            $this->getUserService()->initSystemUsers();
            $output->writeln('<info>初始化系统完毕</info>');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return self::FAILURE;
    }

    protected function initAdminUser($fields, OutputInterface $output)
    {
        $output->writeln("  创建管理员帐号:{$fields['email']}, 密码：{$fields['password']}   ");
        $fields['emailVerified'] = 1;
        $fields['roles'] = ['ROLE_SUPER_ADMIN'];
        $user = $this->getUserService()->getUserByEmail($fields['email']);
        if (empty($user)) {
            $user = $this->getUserService()->createUser($fields);
        }

        $user['currentIp'] = '127.0.0.1';
        $currentUser = new CurrentUser();
        $currentUser->fromArray($user);

        $this->setCurrentUser($currentUser);
        $this->getUserService()->changeUserRoles($user['id'], $user['roles'], $currentUser);
        $output->writeln(' ...<info>成功</info>');
        return $this->getUserService()->getUser($user['id']);
    }

    protected function makeAdminUser(InputInterface $input)
    {
        $nickname = $input->getArgument('nickname');
        $password = $input->getArgument('password');
        $email = $input->getArgument('email');
        if (!empty($nickname) && !empty($password) && !empty($email)) {
            $adminUser = [
                'email'    => $email,
                'nickname' => $nickname,
                'password' => $password,
            ];
        } else {
            $adminUser = [
                'email'    => 'superAdmin@vr.net',
                'nickname' => 'admin',
                'password' => self::DEFAULT_USER_PWD,
            ];
        }
        $adminUser['createdIp'] = '127.0.0.1';
        return $adminUser;
    }

    private function makePassword($password, $salt)
    {
        return base64_encode(hash_hmac('sha256', $password, $salt, true));
    }

    private function checkPassword($password, $hashedValue)
    {
        return strlen($hashedValue) === 0 ? false : password_verify($password, $hashedValue);
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->getBiz()->service('User:UserService');
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}
