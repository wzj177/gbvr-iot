<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Core;
use CoreW\Gii\GiiFactory;
use CoreW\Gii\Template\Easy\DaoImplTemplate;
use CoreW\Gii\Template\Easy\DaoInterfaceTemplate;
use CoreW\Util\ShellColor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class MakeBizDaoCommand extends Command
{
    protected static $defaultName = 'make:biz-dao';
    protected static $defaultDescription = '生成biz dao';

    /**
     *
     * eg1: php webman make:biz -i Idem -t idem -s Plugins\\YangYang\\ApiIdempotent -d N
     * eg2: php webman make:biz -i User
     * eg3: php webman make:biz -i User -t user
     * @return void
     */
    protected function configure()
    {
        $this->addOption('id', '-i', InputOption::VALUE_REQUIRED, '业务名称');
        $this->addOption('dao', '-d', InputOption::VALUE_OPTIONAL, 'dao名称')
            ->addOption('namespace', '-s', InputOption::VALUE_OPTIONAL,'命名空间');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bizId = $input->getOption('id');
        $dao = $input->getOption('dao');
        empty($dao) && $dao = $bizId;
        $namespace = $input->getOption('namespace');
        empty($namespace) && $namespace = "CoreW\\Business";
        $output->writeln(sprintf("正在生成Biz: %s", ShellColor::showInfo($bizId)));
        try {
            $gii = GiiFactory::create('easy', $namespace, $this->getBiz());
            $path = $gii->render([
                'bizId' => $bizId,
                'prefix' => getenv('DB_PREFIX'),
                'dao' => $dao,
                'scene' => 'make-dao',
                'templates' => [
                    'daoInterface' => DaoInterfaceTemplate::class,
                    'daoImpl' => DaoImplTemplate::class,
                ]
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
