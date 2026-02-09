<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\System\Service\SystemService;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Request;
use support\Response;

/**
 * GB28181 系统监控控制器 - 管理后台
 */
class GB28181SystemMonitoringController extends BaseController
{

    /**
     * 获取设备统计信息
     *
     * @param Request $request
     * @return Response
     */
    public function getDeviceStats(Request $request): Response
    {
        try {
            // 设备概览统计
            list('total_count' => $totalCount, 'online_count' => $onlineCount, 'unregister_count' => $unRegisterCount, 'expired_count' => $expiredCount) = $this->getDeviceService()->summaryDevices([]);

            // 获取所有设备用于详细统计
            $allDevices = $this->getDeviceService()->searchDevices([], [], 0, 1000);

            // 通道统计
            $totalChannels = 0;
            $onlineChannels = 0;
            $offlineChannels = 0;
            foreach ($allDevices as $device) {
                $channels = $this->getDeviceService()->getChannelsByDeviceId($device['device_id']);
                $totalChannels += is_countable($channels) ? count($channels) : 0;
                foreach (($channels ?? []) as $channel) {
                    if (($channel['status'] ?? '') === 'online') {
                        $onlineChannels++;
                    } else {
                        $offlineChannels++;
                    }
                }
            }

            // 设备类型分布
            $typeDistribution = $this->calculateDeviceTypeDistribution($allDevices);

            // 厂商分布
            $manufacturerDistribution = $this->calculateManufacturerDistribution($allDevices);

            // 最近活动设备
            $recentActivities = $this->getRecentActivities($allDevices, 5);

            // 24小时在线趋势
            $hourlyStats = $this->getHourlyStats($allDevices);

            return $this->createSuccessJsonResponse([
                // 设备概览
                'total_count' => $totalCount,
                'online_count' => $onlineCount,
                'unregister_count' => $unRegisterCount,
                'expired_count' => $expiredCount,

                // 通道统计
                'total_channels' => $totalChannels,
                'online_channels' => $onlineChannels,
                'offline_channels' => $offlineChannels,

                // 设备类型分布
                'type_distribution' => $typeDistribution,

                // 厂商分布
                'manufacturer_distribution' => $manufacturerDistribution,

                // 最近活动设备
                'recent_activities' => $recentActivities,

                // 24小时在线趋势
                'hourly_stats' => $hourlyStats,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取系统资源使用情况
     *
     * @param Request $request
     * @return \support\Response
     */
    public function getSystemStats(Request $request): \support\Response
    {
        try {
            $stats = $this->getSystemService()->getSystemStats();

            return $this->createSuccessJsonResponse($stats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }


    /**
     * 计算设备类型分布
     *
     * @param array $devices
     * @return array
     */
    private function calculateDeviceTypeDistribution(array $devices): array
    {
        $typeMap = [
            'IPC' => 'ipc',
            'NVR' => 'nvr',
            'DVR' => 'dvr',
            'HCVR' => 'dvr',
            '中心平台' => 'platform',
        ];

        $distribution = [];
        foreach ($devices as $device) {
            $deviceType = $device['device_type'] ?? 'Unknown';
            $typeKey = $typeMap[$deviceType] ?? strtolower($deviceType);
            $typeName = $deviceType;

            if (!isset($distribution[$typeKey])) {
                $distribution[$typeKey] = [
                    'type' => $typeKey,
                    'type_name' => $typeName,
                    'count' => 0,
                ];
            }
            $distribution[$typeKey]['count']++;
        }

        return array_values($distribution);
    }

    /**
     * 计算厂商分布
     *
     * @param array $devices
     * @return array
     */
    private function calculateManufacturerDistribution(array $devices): array
    {
        $manufacturerMap = [];

        foreach ($devices as $device) {
            $manufacturer = $device['manufacturer'] ?? '未知厂商';
            $model = $device['model'] ?? '';

            if (!isset($manufacturerMap[$manufacturer])) {
                $manufacturerMap[$manufacturer] = [
                    'manufacturer' => $manufacturer,
                    'count' => 0,
                    'models' => [],
                ];
            }
            $manufacturerMap[$manufacturer]['count']++;
            if ($model && !in_array($model, $manufacturerMap[$manufacturer]['models'])) {
                $manufacturerMap[$manufacturer]['models'][] = $model;
            }
        }

        return array_values($manufacturerMap);
    }

    /**
     * 获取最近活动设备
     *
     * @param array $devices
     * @param int $limit
     * @return array
     */
    private function getRecentActivities(array $devices, int $limit = 5): array
    {
        // 按最后心跳时间排序
        usort($devices, function ($a, $b) {
            $timeA = strtotime($a['last_heartbeat_at'] ?? '1970-01-01');
            $timeB = strtotime($b['last_heartbeat_at'] ?? '1970-01-01');
            return $timeB <=> $timeA;
        });

        $activities = [];
        for ($i = 0; $i < min($limit, count($devices)); $i++) {
            $device = $devices[$i];
            $activities[] = [
                'device_name' => $device['device_name'] ?? $device['device_id'] ?? '',
                'device_id' => $device['device_id'] ?? '',
                'status' => $device['status'] ?? 'offline',
                'last_seen' => $device['last_heartbeat_at'] ?? $device['updated_at'] ?? '',
            ];
        }

        return $activities;
    }

    /**
     * 获取24小时在线趋势
     *
     * @param array $devices
     * @return array
     */
    private function getHourlyStats(array $devices): array
    {
        $hourlyStats = [];
        $currentHour = (int)date('H');

        for ($i = 0; $i < 24; $i++) {
            $hour = ($currentHour - $i + 24) % 24;
            $hourlyStats[] = [
                'hour' => $hour,
                'online_count' => 0,  // 实际应从历史数据获取
                'total_count' => count($devices),
                'online_rate' => 0,
            ];
        }

        // 反转数组使时间顺序正确
        $hourlyStats = array_reverse($hourlyStats);

        // 填充在线数据（简化处理，使用当前在线状态）
        $onlineCount = count(array_filter($devices, fn($d) => ($d['status'] ?? '') === 'online'));
        foreach ($hourlyStats as &$stat) {
            $stat['online_count'] = $onlineCount;
            $stat['online_rate'] = count($devices) > 0 ? round(($onlineCount / count($devices)) * 100) : 0;
        }

        return $hourlyStats;
    }


    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return SystemService
     */
    private function getSystemService(): SystemService
    {
        return $this->createService('System:SystemService');
    }


    /**
     * 获取录像服务
     *
     * @return mixed
     */
    private function getRecordingService()
    {
        // 在实际实现中，需要创建一个录像服务类
        return $this->createService('Devices:RecordFileService');
    }

    /**
     * 获取报警服务
     *
     * @return mixed
     */
    private function getAlarmService()
    {
        // 在实际实现中，需要创建一个报警服务类
        return $this->createService('Devices:AlarmService');
    }
}