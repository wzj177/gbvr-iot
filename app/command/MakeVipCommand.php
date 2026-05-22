<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Business\BizEnum;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Core;
use support\utils\ArrayToolkit;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;


class MakeVipCommand extends Command
{
    protected static $defaultName = 'make:vip';
    protected static $defaultDescription = '创建系统内部会员';

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
        $helper = $this->getHelper('question');
        $question = new Question('请输入需要生成的会员用户名: ');
        // 获取用户输入的 ID
        $nickname = $helper->ask($input, $output, $question);
        try {
            $result = $this->getVipService()->createSystemVIP($nickname);
            if (empty($result)) {
                throw new \Exception('未知错误');
            }
            $output->writeln("创建成功，用户名：{$nickname}, 初始密码：{$result['password']}");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("创建失败，{$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * @return VIPService
     */
    protected function getVipService()
    {
        return $this->getBiz()->service('VIP:VIPService');
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}
