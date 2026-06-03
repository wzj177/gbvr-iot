<?php

namespace app\process;

use CoreW\Business\Devices\Enums\ChannelTypeEnum;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\Record\Service\RecordPlanService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use CoreW\Core;
use support\Log;
use support\utils\ArrayToolkit;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 自动云端录像进程
 *
 * - 多进程，每个进程负责一部分通道（按 worker id 分片）
 * - 1 秒轮询：检查计划状态 → 时间段 → 限制 → 启/停录像
 * - 内存追踪已在录制的通道，避免重复调用
 * - 进程退出时清理所有录制中的通道
 *
 * 前提约束：云端录像的通道必须开启 auto_live=1
 * 本进程只负责告知 ZLM 开始/停止写文件，流的生命周期由 AutoLiveStreamTask 统一管理。
 */
class AutoRecordProcess
{
    private int $processNum;

    /** @var array<string, array<string, array{'recording': bool}>> 按 worker key 索引的正在录制通道列表 */
    private static array $recordingItems = [];

    /** @var string[] 去重日志 */
    private array $logMessages = [];

    // ==================== 星期映射 ====================

    private const WEEK_DAY_MAP
        = [
            1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU',
            5 => 'FRI', 6 => 'SAT', 7 => 'SUN',
        ];

    public function onWorkerStart(Worker $worker) : void
    {
        if (!((int)env('ENABLE_AUTO_RECORD') ? : 0)) {
            return;
        }

        $this->processNum = (int)env('TASK_RECORD_PROCESS_NUM') ? : 3;

        $worker->onWorkerStop = function ($worker) {
            $this->clearAllRecording($worker->id);
        };

        Timer::add(1, function () use ($worker) {
            try {
                $this->logMessages = [];
                $this->keepRecord($worker->id);
            } catch (\Throwable $e) {
                $this->log()->error('[AutoRecord] keepRecord 异常: ' . $e->getMessage());
            }
        });
    }

    // ==================== 核心循环 ====================

    protected function keepRecord(int $workerId) : void
    {
        $planList = $this->getRecordPlanService()->getPlansWithRanges();
        if (empty($planList)) {
//            $this->loopLog("[进程{$workerId}] 暂无可用录像计划");
            return;
        }

        $plans = ArrayToolkit::index($planList, 'id');
        $workerKey = 'worker_' . $workerId;
        // ==================== 国标设备处理 ====================
        try {
            $this->handleDeviceRecording($workerKey, $workerId, $plans);
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] handleDeviceRecording 错误: ' . $e->getMessage());
        }

        // ==================== 拉流设备处理 ====================
        try {
            $this->handleStreamProxyRecording($workerKey, $workerId, $plans);
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] handleStreamProxyRecording 错误: ' . $e->getMessage());
        }
    }

    /**
     * 处理国标设备录像
     */
    protected function handleDeviceRecording(string $workerKey, int $workerId, array $plans) : void
    {
        $planIds = array_values(array_keys($plans));
        $videoChannels = $this->getOnlineVideoChannels($planIds);
        if (empty($videoChannels)) {
            $this->loopLog("[进程{$workerId}] 暂无已绑定录像计划的在线通道");
            return;
        }

        $myChannels = $this->splitByWorker($videoChannels, $workerId);

        foreach ($myChannels as $channel) {
            $vKey = $channel['stream_id'] ? : ($channel['device_id'] . '_' . $channel['channel_id']);

            $plan = $plans[$channel['record_plan_id']] ?? null;
            if (!$plan) {
                $this->tryStopRecording($workerKey, $vKey, $channel);
                continue;
            }

            // 计划已禁用
            if ($plan['status'] != 1) {
                $this->loopLog($vKey . ' 录像计划已关闭');
                $this->tryStopRecording($workerKey, $vKey, $channel);
                continue;
            }

            // 不在时间范围
            if (!$this->checkTimeRange($plan)) {
                $this->loopLog($vKey . ' 不在时间范围内');
                $this->tryStopRecording($workerKey, $vKey, $channel);
                continue;
            }

            // 天数限制检查
            if ($plan['limit_days'] > 0) {
                $fileDateList = $this->getRecordFileService()->getRecordFileDateListByPlanId($plan['id']);
                if (count($fileDateList) > $plan['limit_days']) {
                    $this->cleanOldestDateFiles($plan, $fileDateList);
                }
            }

            // 空间限制检查
            if ($plan['limit_space'] > 0) {
                $fileSize = $this->getRecordFileService()->getRecordFileSizeByPlanId($plan['id']);
                if ($fileSize > $plan['limit_space'] && $this->handleOverSpace($plan, $vKey)) {
                    $this->tryStopRecording($workerKey, $vKey, $channel);
                    continue;
                }
            }

            // 已在录制中则跳过，否则启动录像
            if (empty(self::$recordingItems[$workerKey][$vKey]['recording'])) {
                $this->startRecording($workerKey, $vKey, $channel);
            }
        }
    }

    /**
     * 处理流代理录像
     */
    protected function handleStreamProxyRecording(string $workerKey, int $workerId, array $plans) : void
    {
        try {
            // 获取所有在线且绑定了录像计划的流代理
            $proxies = $this->getStreamProxyService()->searchProxies([
                'status'          => 'online',
                'recordPlanId_GT' => 0,
            ], [], 0, 1000);

            if (empty($proxies)) {
                return;
            }

            // 按worker分片
            $myProxies = $this->splitByWorker($proxies, $workerId);

            foreach ($myProxies as $proxy) {
                $vKey = 'stream_proxy_' . $proxy['id'];
                $plan = $plans[$proxy['record_plan_id']] ?? null;

                if (!$plan) {
                    $this->tryStopStreamProxyRecording($workerKey, $vKey, $proxy);
                    continue;
                }

                // 计划已禁用
                if ($plan['status'] != 1) {
                    $this->loopLog($vKey . ' 录像计划已关闭');
                    $this->tryStopStreamProxyRecording($workerKey, $vKey, $proxy);
                    continue;
                }

                // 不在时间范围
                if (!$this->checkTimeRange($plan)) {
                    $this->loopLog($vKey . ' 不在时间范围内');
                    $this->tryStopStreamProxyRecording($workerKey, $vKey, $proxy);
                    continue;
                }

                // 天数限制检查
                if ($plan['limit_days'] > 0) {
                    $fileDateList = $this->getRecordFileService()->getRecordFileDateListByPlanId($plan['id']);
                    if (count($fileDateList) > $plan['limit_days']) {
                        $this->cleanOldestDateFiles($plan, $fileDateList);
                    }
                }

                // 空间限制检查
                if ($plan['limit_space'] > 0) {
                    $fileSize = $this->getRecordFileService()->getRecordFileSizeByPlanId($plan['id']);
                    if ($fileSize > $plan['limit_space'] && $this->handleOverSpace($plan, $vKey)) {
                        $this->tryStopStreamProxyRecording($workerKey, $vKey, $proxy);
                        continue;
                    }
                }

                // 已在录制中则跳过，否则启动录像
                if (empty(self::$recordingItems[$workerKey][$vKey]['recording'])) {
                    $this->startStreamProxyRecording($workerKey, $vKey, $proxy);
                }
            }
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] handleStreamProxyRecording 异常: ' . $e->getMessage());
        }
    }

    /**
     * 启动流代理录像
     */
    protected function startStreamProxyRecording(string $workerKey, string $vKey, array $proxy) : void
    {
        try {
            $zlmClient = $this->getGb28181Service()->getZlmClientByServerId($proxy['media_server_id']);

            // 先检查 ZLM 实际录制状态（防止进程重启后内存状态丢失）
            $isRecording = $zlmClient->isRecording($proxy['vhost'], $proxy['app'], $proxy['stream']);
            if ($isRecording) {
                self::$recordingItems[$workerKey][$vKey] = ['recording' => true];
                $this->loopLog('[AutoRecord] 流代理ZLM已在录制，同步内存状态: ' . $vKey);
                return;
            }

            $result = $zlmClient->startRecord($proxy['vhost'], $proxy['app'], $proxy['stream']);

            if ($result) {
                self::$recordingItems[$workerKey][$vKey] = ['recording' => true];
                $this->getStreamProxyService()->updateProxy($proxy['id'], ['record_status' => 1]);
                $this->log()->info('[AutoRecord] 流代理开始录制: ' . $vKey);
            } else {
                $this->log()->warning('[AutoRecord] 流代理调用 startRecord 失败: ' . $vKey);
            }
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] 流代理启动录制异常: ' . $vKey . ' ' . $e->getMessage());
        }
    }

    /**
     * 停止流代理录像
     */
    protected function tryStopStreamProxyRecording(string $workerKey, string $vKey, array $proxy) : void
    {
        if (!empty(self::$recordingItems[$workerKey][$vKey]['recording'])) {
            try {
                $zlmClient = $this->getGb28181Service()->getZlmClientByServerId($proxy['media_server_id']);
                $zlmClient->stopRecord($proxy['vhost'], $proxy['app'], $proxy['stream']);

                unset(self::$recordingItems[$workerKey][$vKey]);
                $this->getStreamProxyService()->updateProxy($proxy['id'], ['record_status' => 0]);
                $this->log()->info('[AutoRecord] 流代理停止录制: ' . $vKey);
            } catch (\Throwable $e) {
                $this->log()->error('[AutoRecord] 流代理停止录制异常: ' . $vKey . ' ' . $e->getMessage());
            }
        }
    }

    // ==================== 启/停录像 ====================

    /**
     * 启动录像：调用 ZLM startRecord
     *
     * 前提：云端录像的通道必须开启 auto_live=1，由 AutoLiveStreamTask 负责维持流，
     * 本进程只负责告知 ZLM 开始/停止写文件，不负责流的生命周期管理。
     */
    protected function startRecording(string $workerKey, string $vKey, array $channel) : void
    {
        try {
            // 流不存在则等待，AutoLiveStreamTask 会负责拉起流
            $mediaList = $this->getGb28181Service()->getMediaList($channel['media_server_id'], $channel['stream_id']);
            if (empty($mediaList)) {
                $this->loopLog('[AutoRecord] 流不存在，等待 AutoLive 拉起: ' . $vKey);
                return;
            }

            $zlmClient = $this->getGb28181Service()->getZlmClientByServerId($channel['media_server_id']);

            // 先检查 ZLM 实际录制状态（防止进程重启后内存状态丢失导致重复调用）
            $isRecording = $zlmClient->isRecording('__defaultVhost__', 'rtp', $channel['stream_id']);
            if ($isRecording) {
                // ZLM 已在录制，同步内存状态即可
                self::$recordingItems[$workerKey][$vKey] = ['recording' => true];
                $this->loopLog('[AutoRecord] ZLM已在录制，同步内存状态: ' . $vKey);
                return;
            }

            $result = $zlmClient->startRecord('__defaultVhost__', 'rtp', $channel['stream_id']);

            if ($result) {
                self::$recordingItems[$workerKey][$vKey] = ['recording' => true];
                $this->getDeviceService()->updateChannel($channel['id'], ['record_status' => 1]);
                $this->log()->info('[AutoRecord] 开始录制: ' . $vKey);
            } else {
                $this->log()->warning('[AutoRecord] startRecord 失败: ' . $vKey);
            }
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] 启动录制异常: ' . $vKey . ' ' . $e->getMessage());
        }
    }

    /**
     * 停止录像：调用 ZLM stopRecord
     *
     * 只停止 ZLM 写文件，流本身由 AutoLiveStreamTask 管理，不在此处处理。
     */
    protected function tryStopRecording(string $workerKey, string $vKey, array $channel) : void
    {
        if (!isset(self::$recordingItems[$workerKey][$vKey])) {
            return;
        }

        unset(self::$recordingItems[$workerKey][$vKey]);

        try {
            $this->getGb28181Service()
                ->getZlmClientByServerId($channel['media_server_id'])
                ->stopRecord('__defaultVhost__', 'rtp', $channel['stream_id']);
            $this->getDeviceService()->updateChannel($channel['id'], ['record_status' => 0]);
            $this->log()->info('[AutoRecord] 停止录制: ' . $vKey);
        } catch (\Throwable $e) {
            $this->log()->error('[AutoRecord] 停止录制异常: ' . $vKey . ' ' . $e->getMessage());
        }
    }


    /**
     * 进程退出时清理所有录制
     */
    protected function clearAllRecording(int $workerId) : void
    {
        $workerKey = 'worker_' . $workerId;
        $items = self::$recordingItems[$workerKey] ?? [];

        if (empty($items)) {
            return;
        }

        $this->log()->info("[AutoRecord] 进程 {$workerId} 退出，清理录制通道: " . count($items));

        // 获取所有正在录制的通道
        $channels = $this->getDeviceService()->searchChannels(
            ['record_status' => 1],
            [],
            0,
            PHP_INT_MAX
        );

        $myChannels = $this->splitByWorker($channels, $workerId);

        foreach ($myChannels as $channel) {
            $vKey = $channel['stream_id'] ? : ($channel['device_id'] . '_' . $channel['channel_id']);
            if (!empty(self::$recordingItems[$workerKey][$vKey]['recording'])) {
                $this->tryStopRecording($workerKey, $vKey, $channel);
            }
        }
    }

    // ==================== 时间段检查 ====================

    protected function checkTimeRange(array $plan) : bool
    {
        $timeRanges = $plan['time_range_list'] ?? [];
        if (empty($timeRanges)) {
            return false;
        }

        $todayWeekDay = self::WEEK_DAY_MAP[(int)date('N')] ?? '';
        $now = date('H:i');

        foreach ($timeRanges as $range) {
            if (strtoupper($range['week_day']) !== $todayWeekDay) {
                continue;
            }
            if ($range['start_time'] <= $now && $range['end_time'] >= $now) {
                return true;
            }
        }

        return false;
    }

    // ==================== 限制处理 ====================

    /**
     * 清理最早一天的文件（天数超限）
     */
    protected function cleanOldestDateFiles(array $plan, array $fileDateList) : void
    {
        $diffCount = count($fileDateList) - $plan['limit_days'];
        for ($i = 0; $i < $diffCount; $i++) {
            $date = $fileDateList[$i];
            $this->getRecordFileService()->softDeleteByPlanIdAndDate($plan['id'], $date);
            $this->log()->info("[AutoRecord] 天数超限，清理: plan={$plan['id']} date={$date}");
        }
    }

    /**
     * 空间超限处理
     * @return bool 是否需要停止录像
     */
    protected function handleOverSpace(array $plan, string $vKey) : bool
    {
        $fileDateList = $this->getRecordFileService()->getRecordFileDateListByPlanId($plan['id']);

        if ($plan['over_step_plan'] === 'del_file') {
            // 删除最早一天的文件
            if (!empty($fileDateList)) {
                $this->getRecordFileService()->softDeleteByPlanIdAndDate($plan['id'], $fileDateList[0]);
                $this->log()->info("[AutoRecord] 空间超限，删除最早日期文件: plan={$plan['id']} date={$fileDateList[0]}");
            }
            // 删完后继续录
            return false;
        }

        // stop_record: 停止录像
        $this->log()->info("[AutoRecord] 空间超限，停止录制: plan={$plan['id']} vKey={$vKey}");
        return true;
    }

    // ==================== 多进程分片 ====================

    protected function splitByWorker(array $channels, int $workerId) : array
    {
        $limit = (int)ceil(count($channels) / $this->processNum);
        if ($limit <= 0) {
            return [];
        }
        $chunks = array_chunk($channels, $limit);
        return $chunks[$workerId] ?? [];
    }

    // ==================== 查询 ====================

    protected function getOnlineVideoChannels(array $planIds) : array
    {
        return $this->getDeviceService()->searchChannels(
            [
                'status'             => DeviceStatusEnum::ONLINE->value,
                'record_plan_ids'    => $planIds,
                'channel_types'      => [ChannelTypeEnum::CAMERA->value, ChannelTypeEnum::IPC->value],
                'media_server_id_NE' => 'none',
            ],
            [],
            0,
            PHP_INT_MAX
        );
    }

    // ==================== 日志 ====================

    protected function loopLog(string $message) : void
    {
        if (!in_array($message, $this->logMessages)) {
            $this->log()->debug($message);
            $this->logMessages[] = $message;
        }
    }

    protected function log() : \Monolog\Logger
    {
        return Log::channel('auto_record');
    }

    // ==================== Service getters ====================

    protected function getBfw() : \CoreW\Bfw
    {
        return Core::instance();
    }

    protected function getDeviceService() : DeviceService
    {
        return $this->getBfw()->service('Devices:DeviceService');
    }

    protected function getGb28181Service() : Gb28181Service
    {
        return $this->getBfw()->offsetGet('gb28181_service');
    }

    protected function getRecordPlanService() : RecordPlanService
    {
        return $this->getBfw()->service('Record:RecordPlanService');
    }

    protected function getRecordFileService() : RecordFileService
    {
        return $this->getBfw()->service('RecordFile:RecordFileService');
    }

    protected function getStreamProxyService() : \CoreW\Business\StreamProxy\Service\StreamProxyService
    {
        return $this->getBfw()->service('StreamProxy:StreamProxyService');
    }

}
