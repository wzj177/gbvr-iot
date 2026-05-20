<?php

namespace app\command;

use CoreW\Core;
use CoreW\Bfw;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 生成 GB28181 报警测试数据
 *
 * 用法:
 * php webman generate:alarm-data
 * php webman generate:alarm-data --device_id=34020000001320000109
 * php webman generate:alarm-data --count=50
 * php webman generate:alarm-data --type=video
 * php webman generate:alarm-data --days=7
 */
class GenerateAlarmDataCommand extends Command
{
    protected static $defaultName = 'generate:alarm-data';
    protected static $defaultDescription = '生成 GB28181 报警事件测试数据';

    protected function configure()
    {
        $this
            ->addOption('device_id', null, InputOption::VALUE_OPTIONAL, '设备ID，默认51010700001320000002')
            ->addOption('channel_id', null, InputOption::VALUE_OPTIONAL, '通道ID，默认等于设备ID')
            ->addOption('count', null, InputOption::VALUE_OPTIONAL, '每种类型生成数量，默认20条', 20)
            ->addOption('type', null, InputOption::VALUE_OPTIONAL, '报警类型: all/device/video/storage，默认all', 'all')
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, '生成最近N天的数据，默认7天', 7)
            ->addOption('no_plan', null, InputOption::VALUE_NONE, '不关联报警计划')
            ->addOption('create_plan', null, InputOption::VALUE_NONE, '自动创建默认报警计划（如果不存在）');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $biz = Core::initCiBiz();
        $db = $biz['db'];

        // 获取选项参数
        $deviceId = $input->getOption('device_id') ?? '51010700001320000002';
        $channelId = $input->getOption('channel_id') ?? $deviceId; // 默认通道ID等于设备ID
        $count = (int)$input->getOption('count');
        $typeFilter = $input->getOption('type');
        $days = (int)$input->getOption('days');
        $noPlan = $input->getOption('no_plan');
        $createPlan = $input->getOption('create_plan');

        // 获取或创建报警计划ID
        $planId = null;
        if (!$noPlan) {
            if ($createPlan) {
                $planId = $this->getOrCreateDefaultPlan($db, $output, $deviceId, $channelId);
            } else {
                // 尝试查找现有计划
                $planId = $this->findExistingPlan($db, $deviceId, $channelId);
                if ($planId) {
                    $output->writeln("<info>使用现有报警计划 ID: {$planId}</info>");
                } else {
                    $output->writeln("<comment>未找到报警计划，自动创建新计划</comment>");
                    $planId = $this->getOrCreateDefaultPlan($db, $output, $deviceId, $channelId);
                }
            }
        }

        // 显示将要使用的 planId
        if ($planId) {
            $output->writeln("<info>报警事件将绑定到计划 ID: {$planId}</info>");
        } else {
            $output->writeln("<comment>报警事件不绑定计划</comment>");
        }
        $output->writeln('');

        $output->writeln("<info>开始生成报警测试数据...</info>");
        $output->writeln("设备ID: {$deviceId}");
        $output->writeln("通道ID: {$channelId}");
        $output->writeln("每种类型数量: {$count} 条");
        $output->writeln("时间范围: 最近 {$days} 天");
        $output->writeln("类型过滤: {$typeFilter}");
        $output->writeln('');

        // 生成报警数据模板
        $alarmTemplates = $this->getAlarmTemplates($typeFilter);

        if (empty($alarmTemplates)) {
            $output->writeln('<error>无效的报警类型过滤器</error>');
            return self::FAILURE;
        }

        $created = 0;
        $now = time();
        $totalToCreate = count($alarmTemplates) * $count;

        // 遍历每个报警模板，每个模板生成 count 条
        foreach ($alarmTemplates as $template) {
            $output->writeln("<comment>生成: {$template['name']}</comment>");

            for ($i = 0; $i < $count; $i++) {
                // 随机生成报警时间（最近N天内）
                $randomSeconds = rand(0, $days * 86400);
                $alarmTime = date('Y-m-d H:i:s.v', $now - $randomSeconds);
                $recvTime = date('Y-m-d H:i:s.v', $now - $randomSeconds + rand(0, 5));

                // 随机位置（中国境内）
                $longitude = 100 + rand(0, 200) / 10 + rand(0, 100000) / 100000;
                $latitude = 25 + rand(0, 150) / 10 + rand(0, 100000) / 100000;

                $data = [
                    'device_id'     => $deviceId,
                    'channel_id'    => $channelId,
                    'level'         => $template['level'],
                    'method'        => $template['method'],
                    'type'          => $template['type'],
                    'eventtype'     => $template['eventtype'] ?? null,
                    'description'   => $this->generateDescription($template),
                    'longitude'     => $longitude,
                    'latitude'      => $latitude,
                    'alarm_time'    => $alarmTime,
                    'recv_time'     => $recvTime,
                    'alarm_plan_id' => $planId,
                    'raw_payload'   => $this->generateRawPayload($template),
                    'created_at'    => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ];

                try {
                    $db->insert('gv_alarm_event', $data);
                    $created++;

                    // 显示进度
                    if ($created % 10 === 0) {
                        $output->write(".");
                    }
                } catch (\Exception $e) {
                    $output->writeln("<error>插入失败: {$e->getMessage()}</error>");
                }
            }
            $output->writeln('');
        }

        $output->writeln("<info>成功生成 {$created} 条报警测试数据（共 " . count($alarmTemplates) . " 种类型，每种 {$count} 条）</info>");

        // 显示统计信息
        $this->showStatistics($db, $output, $deviceId, $channelId);

        return self::SUCCESS;
    }

    /**
     * 获取报警模板
     */
    private function getAlarmTemplates(string $typeFilter = 'all') : array
    {
        $templates = [];

        // AlarmMethod=2 (设备报警)
        if ($typeFilter === 'all' || $typeFilter === 'device') {
            $deviceAlarms = [
                ['level' => 2, 'method' => 2, 'type' => 1, 'eventtype' => null, 'name' => '视频丢失报警'],
                ['level' => 1, 'method' => 2, 'type' => 2, 'eventtype' => null, 'name' => '设备防拆报警'],
                ['level' => 3, 'method' => 2, 'type' => 3, 'eventtype' => null, 'name' => '存储设备磁盘满报警'],
                ['level' => 2, 'method' => 2, 'type' => 4, 'eventtype' => null, 'name' => '设备高温报警'],
                ['level' => 2, 'method' => 2, 'type' => 5, 'eventtype' => null, 'name' => '设备低温报警'],
            ];
            $templates = array_merge($templates, $deviceAlarms);
        }

        // AlarmMethod=5 (视频报警/智能分析)
        if ($typeFilter === 'all' || $typeFilter === 'video') {
            $videoAlarms = [
                ['level' => 1, 'method' => 5, 'type' => 1, 'eventtype' => null, 'name' => '人工视频报警'],
                ['level' => 2, 'method' => 5, 'type' => 2, 'eventtype' => null, 'name' => '运动目标检测报警'],
                ['level' => 2, 'method' => 5, 'type' => 3, 'eventtype' => null, 'name' => '遗留物检测报警'],
                ['level' => 2, 'method' => 5, 'type' => 4, 'eventtype' => null, 'name' => '物体移除检测报警'],
                ['level' => 1, 'method' => 5, 'type' => 5, 'eventtype' => null, 'name' => '绊线检测报警'],
                ['level' => 1, 'method' => 5, 'type' => 6, 'eventtype' => 1, 'name' => '入侵检测报警-进入区域'],
                ['level' => 1, 'method' => 5, 'type' => 6, 'eventtype' => 2, 'name' => '入侵检测报警-离开区域'],
                ['level' => 2, 'method' => 5, 'type' => 7, 'eventtype' => null, 'name' => '逆行检测报警'],
                ['level' => 2, 'method' => 5, 'type' => 8, 'eventtype' => null, 'name' => '徘徊检测报警'],
                ['level' => 3, 'method' => 5, 'type' => 9, 'eventtype' => null, 'name' => '流量统计报警'],
                ['level' => 2, 'method' => 5, 'type' => 10, 'eventtype' => null, 'name' => '密度检测报警'],
                ['level' => 2, 'method' => 5, 'type' => 11, 'eventtype' => null, 'name' => '视频异常检测报警'],
                ['level' => 3, 'method' => 5, 'type' => 12, 'eventtype' => null, 'name' => '快速移动报警'],
                ['level' => 2, 'method' => 5, 'type' => 13, 'eventtype' => null, 'name' => '图像遮挡报警'],
            ];
            $templates = array_merge($templates, $videoAlarms);
        }

        // AlarmMethod=6 (存储故障)
        if ($typeFilter === 'all' || $typeFilter === 'storage') {
            $storageAlarms = [
                ['level' => 3, 'method' => 6, 'type' => 1, 'eventtype' => null, 'name' => '存储设备磁盘故障报警'],
                ['level' => 2, 'method' => 6, 'type' => 2, 'eventtype' => null, 'name' => '存储设备风扇故障报警'],
            ];
            $templates = array_merge($templates, $storageAlarms);
        }

        return $templates;
    }

    /**
     * 生成报警描述
     */
    private function generateDescription(array $template) : string
    {
        $descriptions = [
            '检测到异常活动，请及时处理',
            '系统自动检测到报警事件',
            '监控区域发生异常情况',
            '智能分析系统触发报警',
            '设备上报异常状态',
        ];

        $base = $descriptions[array_rand($descriptions)];
        return "[{$template['name']}] {$base}";
    }

    /**
     * 生成原始报文
     */
    private function generateRawPayload(array $template) : string
    {
        $sn = str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $deviceId = '34020000001320000109';
        $now = date('Y-m-d\TH:i:s.v', time());

        return "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n" .
            "<Notify>\r\n" .
            "  <CmdType>Alarm</CmdType>\r\n" .
            "  <SN>{$sn}</SN>\r\n" .
            "  <DeviceID>{$deviceId}</DeviceID>\r\n" .
            "  <AlarmPriority>{$template['level']}</AlarmPriority>\r\n" .
            "  <AlarmMethod>{$template['method']}</AlarmMethod>\r\n" .
            ($template['type'] ? "  <AlarmType>{$template['type']}</AlarmType>\r\n" : "") .
            ($template['eventtype'] ? "  <AlarmTypeParam><EventType>{$template['eventtype']}</EventType></AlarmTypeParam>\r\n" : "") .
            "  <AlarmTime>{$now}</AlarmTime>\r\n" .
            "</Notify>";
    }

    /**
     * 显示统计信息
     */
    private function showStatistics($db, OutputInterface $output, string $deviceId, string $channelId) : void
    {
        $output->writeln('');
        $output->writeln('<info>=== 报警数据统计 ===</info>');

        // 按级别统计
        $levelStats = $db->fetchAll(
            "SELECT level, COUNT(*) as count FROM gv_alarm_event
             WHERE device_id = ? AND channel_id = ?
             GROUP BY level ORDER BY level",
            [$deviceId, $channelId]
        );

        $output->writeln('<comment>按报警级别统计:</comment>');
        foreach ($levelStats as $stat) {
            $levelName = ['一级警情', '二级警情', '三级警情', '四级警情'][$stat['level'] - 1] ?? '未知';
            $output->writeln("  级别{$stat['level']} ({$levelName}): {$stat['count']} 条");
        }

        // 按方式统计
        $methodStats = $db->fetchAll(
            "SELECT method, COUNT(*) as count FROM gv_alarm_event
             WHERE device_id = ? AND channel_id = ?
             GROUP BY method ORDER BY method",
            [$deviceId, $channelId]
        );

        $output->writeln('');
        $output->writeln('<comment>按报警方式统计:</comment>');
        $methodNames = [
            1 => '电话报警',
            2 => '设备报警',
            3 => '短信报警',
            4 => 'GPS报警',
            5 => '视频报警',
            6 => '设备故障报警',
            7 => '其他报警',
        ];
        foreach ($methodStats as $stat) {
            $methodName = $methodNames[$stat['method']] ?? '未知';
            $output->writeln("  方式{$stat['method']} ({$methodName}): {$stat['count']} 条");
        }

        // 按类型统计（仅视频报警）
        $videoTypeStats = $db->fetchAll(
            "SELECT type, COUNT(*) as count FROM gv_alarm_event
             WHERE device_id = ? AND channel_id = ? AND method = 5
             GROUP BY type ORDER BY type",
            [$deviceId, $channelId]
        );

        if (!empty($videoTypeStats)) {
            $output->writeln('');
            $output->writeln('<comment>视频报警类型统计:</comment>');
            $videoTypes = [
                1  => '人工视频报警', 2 => '运动目标检测', 3 => '遗留物检测',
                4  => '物体移除检测', 5 => '绊线检测', 6 => '入侵检测',
                7  => '逆行检测', 8 => '徘徊检测', 9 => '流量统计',
                10 => '密度检测', 11 => '视频异常检测', 12 => '快速移动',
                13 => '图像遮挡',
            ];
            foreach ($videoTypeStats as $stat) {
                $typeName = $videoTypes[$stat['type']] ?? '未知';
                $output->writeln("  类型{$stat['type']} ({$typeName}): {$stat['count']} 条");
            }
        }

        $output->writeln('');
    }

    /**
     * 查找现有的报警计划
     */
    private function findExistingPlan($db, string $deviceId, string $channelId) : ?int
    {
        $plan = $db->fetchAssoc(
            "SELECT ap.id FROM gv_alarm_plan ap
             INNER JOIN gv_alarm_plan_channel apc ON apc.alarm_plan_id = ap.id
             WHERE apc.device_id = ? AND apc.channel_id = ? AND ap.status = 1
             LIMIT 1",
            [$deviceId, $channelId]
        );

        return $plan ? (int)$plan['id'] : null;
    }

    /**
     * 获取或创建默认报警计划
     */
    private function getOrCreateDefaultPlan($db, OutputInterface $output, string $deviceId, string $channelId) : int
    {
        // 先查找现有计划
        $existingPlanId = $this->findExistingPlan($db, $deviceId, $channelId);
        if ($existingPlanId) {
            return $existingPlanId;
        }

        $output->writeln('<info>创建默认报警计划...</info>');

        $now = date('Y-m-d H:i:s');

        // 创建报警计划
        $planData = [
            'name'                  => '默认报警预案-' . substr($deviceId, -8),
            'status'                => 1,
            'remark'                => '自动生成的默认报警预案，匹配所有视频报警',
            'snapshot_interval_sec' => 10, // 10秒抓拍一次
            'record_duration_sec'   => 60,   // 录像60秒
            'alarm_level'           => json_encode([1, 2, 3, 4]), // 所有级别
            'alarm_method'          => json_encode([5]), // 仅视频报警
            'alarm_type'            => json_encode([]), // 所有类型
            'alarm_eventtype'       => json_encode([]), // 所有事件类型
            'created_at'            => $now,
            'updated_at'            => $now,
        ];

        $db->insert('gv_alarm_plan', $planData);
        $planId = (int)$db->lastInsertId();

        $output->writeln("<info>创建报警计划 ID: {$planId}</info>");

        // 关联通道
        $channelData = [
            'alarm_plan_id' => $planId,
            'device_id'     => $deviceId,
            'channel_id'    => $channelId,
            'enabled'       => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $db->insert('gv_alarm_plan_channel', $channelData);

        $output->writeln("<info>关联通道: {$deviceId}/{$channelId}</info>");

        return $planId;
    }
}
