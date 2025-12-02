<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Core;
use CoreW\Gii\GiiFactory;
use CoreW\Util\ShellColor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class MakeBizCommand extends Command
{
    protected static $defaultName = 'make:biz';
    protected static $defaultDescription = '生成biz（参数1：--id 业务名称【必填】；参数2：--table 数据表名称【不必填，默认小写业务名】；参数3：--namespace  命名空间【不必填，默认CoreW\Business;插件使用则{plugin}\Business；参数4：--dao y】）';

    /**
     *
     * eg1: php webman make:biz -i Idem -t idem -s Plugins\\YangYang\\ApiIdempotent -d N
     * eg2: php webman make:biz -i User
     * eg3: php webman make:biz -i User -t user
     * @return void
     */
    protected function configure()
    {
        $this->addOption('id', '-i', InputOption::VALUE_REQUIRED, '业务名称')
            ->addOption('table', '-t', InputOption::VALUE_OPTIONAL,'数据表名称')
            ->addOption('namespace', '-s', InputOption::VALUE_OPTIONAL,'命名空间')
            ->addOption('dao', '-d', InputOption::VALUE_OPTIONAL,'是否使用dao层');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bizId = $input->getOption('id');
        if (empty($bizId)) {
            $output->writeln(ShellColor::showError("请输入业务名称"));
            return self::FAILURE;
        }

        $table = $input->getOption('table');
        $namespace = $input->getOption('namespace');
        $useDao = $input->getOption('dao');
        empty($namespace) && $namespace = "CoreW\\Business";
        $output->writeln(sprintf("正在生成Biz: %s", ShellColor::showInfo($bizId)));
        try {
            $gii = GiiFactory::create('easy', $namespace, $this->getBiz());
            $path = $gii->render([
                'tableName' => $table,
                'bizId' => $bizId,
                'prefix' => getenv('DB_PREFIX'),
                'useDao' => $useDao
            ]);
            $output->writeln(ShellColor::showInfo("{$path}已创建"));
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(ShellColor::showError("生成失败:{$e->getMessage()}"));
            return self::FAILURE;
        }
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }

}
