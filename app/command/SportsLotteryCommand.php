<?php

namespace app\command;

use CoreW\ToolKits\CURLHttpClient;
use support\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class SportsLotteryCommand extends Command
{
    protected static $defaultName = 'Lottery:test';
    protected static $defaultDescription = '彩票统计测试';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addOption('gameNo', '-g', InputOption::VALUE_REQUIRED, '--gameNo:排列三=35；排列五=350133；七星彩=04');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $gameNo = $input->getOption('gameNo');
        if (!$gameNo) {
            $output->writeln("参数错误！！！");
            return self::FAILURE;
        }
        $termLimits = 10 * 10000 * 10000;
        $pageSize = $termLimits;
        $rawResult = $this->getHttpClient()->get($this->getBaseUri(), [
            'gameNo'     => $gameNo,
            'provinceId' => 0,
            'pageNo'     => 1,
            'pageSize'   => $pageSize,
            'isVerify'   => 1,
            'termLimits' => $termLimits,
        ]);
        $response = json_decode($rawResult['response']['body'], true);
        if ($response['errorCode'] != 0) {
            $output->writeln("请求数据失败了：{$response['errorMessage']}");
            return self::FAILURE;
        }
        $items = $response['value']['list'];
        $total = count($items);
        $label = $this->lotteryTypeDicts($gameNo);
        $content = "{$label}历史开奖{$termLimits}期中，一共有{$total}数据,下面是按最多次倒序排列的结果：[";
        $orderByLotteryDrawNums = array_column($items, 'lotteryDrawNum');
        array_multisort($items, $orderByLotteryDrawNums, SORT_ASC);
        $codes = array_map(function ($code) {
            $code = str_replace(" ", "", $code);
            return $code;
        }, array_column($items, 'lotteryDrawResult'));
        $values = array_count_values($codes);
        arsort($values);
        $values = array_flip($values);
        $output->writeln($content);
        print_r($values);
        $codeStrs = array_map(function ($code) {
            return "'{$code}'";
        }, $codes);
        $content .= implode(',', $codeStrs) . "]老师每天会记录一个三位数的数字，现在我把记录的到的数字发给你";
        $max = max($codes);
        $min = min($codes);
        $avg = floor(array_sum($codes) / $termLimits);
        $output->writeln("输出最近{$termLimits}期，{$pageSize}条数据：");
        Log::channel('console')->info($content);
        file_put_contents(runtime_path() . '/' . $gameNo . 'log', $content);
        $output->writeln("这些三位数中，最大的数是{$max}，最小的数是{$min}，所有数的平均值是{$avg}。");
        // 2023-03-10 第一次计算结果是：这些三位数中，最大的数是998，最小的数是001，所有数的平均值是500.153。
        return self::SUCCESS;
    }

    protected function lotteryTypeDicts($key)
    {
        $items = [
            '35'     => '排列三',
            '350133' => '排列五',
            '04'     => '七星彩',
        ];

        return $items[$key] ?? '';
    }

    protected function getHttpClient()
    {
        $client = new CURLHttpClient([
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36 Edg/110.0.1587.63',
        ]);
        $client->setConfig([
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HEADER         => true,
        ]);
        $client->setConnectionTimeoutInMillis(60 * 1000);
        $client->setSocketTimeoutInMillis(60 * 1000);

        return $client;
    }

    protected function getBaseUri()
    {
        return 'https://webapi.sporttery.cn/gateway/lottery/getHistoryPageListV1.qry';
    }
}
