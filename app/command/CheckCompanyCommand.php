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


class CheckCompanyCommand extends Command
{
    protected static $defaultName = 'company:check';
    protected static $defaultDescription = '审核公司';

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
        $output->writeln('未审核企业列表');
        // 创建一个新的 Table 实例
        $table = new Table($output);
        // 设置表头
        $table->setHeaders(['ID', '企业名称', '状态']);
        $companyList = $this->getVipService()->searchCompanyList(['status' => BizEnum::VIP_COMPANY_STATUS_WAIT], ['id' => 'DESC'], 0, PHP_INT_MAX);
        if (empty($companyList)) {
            $output->writeln('暂无待审核企业');
            return self::SUCCESS;
        }
        foreach ($companyList as $item) {
            $table->addRow([$item['id'], $item['name'], BizEnum::getVipCompanyStatusItems($item['status'])]);
        }

        $table->render();
        $helper = $this->getHelper('question');
        $question = new Question('请输入需要审核的ID: ');
        // 获取用户输入的 ID
        $id = $helper->ask($input, $output, $question);
        $ids = ArrayToolkit::column($companyList, 'id');
        if (!in_array($id, $ids)) {
            $output->writeln('ID不存在');
            return self::SUCCESS;
        }
        // 提示用户选择
        $choices = ['拒绝', '通过'];
        $choiceQuestion = new ChoiceQuestion(
            '请选择审核项 [0=拒绝, -1=通过]: ',
            $choices,
            0 // 默认选项为第一个选项 (通过)
        );

        // 让用户选择操作
        $action = $helper->ask($input, $output, $choiceQuestion);
        // 根据选择的操作执行相应逻辑
        $reason = '';
        if ($action === '通过') {
            $status = 1;
        } else {
            $status = -1;
            $reason = '资料不符合要求';
        }

        $result = $this->getVipService()->checkCompany($id, $status, $reason);
        if (!$result) {
            $output->writeln('审核失败');
            return self::SUCCESS;
        }
        $output->writeln('审核成功');

        return self::SUCCESS;
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
