<?php

namespace app\command;

use CoreW\Bfw;
use CoreW\Core;
use CoreW\Gii\GiiFactory;
use CoreW\Gii\Template\Easy\ServiceInterfaceTemplate;
use CoreW\Gii\Template\Easy\ServiceImplTemplate;
use CoreW\Util\ShellColor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MakeBizServiceCommand extends Command
{
    protected static $defaultName = 'make:biz-service';
    protected static $defaultDescription = '生成业务服务类（Service Interface & Implementation）';

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this->addOption('id', '-i', InputOption::VALUE_REQUIRED, '业务名称')
            ->addOption('namespace', '-s', InputOption::VALUE_OPTIONAL, '命名空间');
    }

    /**
     * Execute the command.
     *
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

        $namespace = $input->getOption('namespace');
        empty($namespace) && $namespace = "CoreW\\Business";
        $output->writeln(sprintf("正在生成Service: %s", ShellColor::showInfo($bizId)));

        try {
            $gii = GiiFactory::create('easy', $namespace, $this->getBiz());
            $path = $gii->render([
                'bizId'     => $bizId,
                'scene'     => 'make-service',
                'templates' => [
                    'serviceInterface' => ServiceInterfaceTemplate::class,
                    'serviceImpl'      => ServiceImplTemplate::class,
                ],
            ]);
            $output->writeln(ShellColor::showInfo("{$path}已创建"));
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(ShellColor::showError("生成失败:{$e->getMessage()}"));
            return self::FAILURE;
        }
    }

    /**
     * Get business framework instance.
     *
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::initCiBiz();
    }
}