<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Request;
use support\Response;
use CoreW\Business\System\Service\SystemService;

/**
 * 系统监控控制器
 * 提供CPU、内存、网络、磁盘IO等系统资源监控接口
 */
class SystemMonitoringController extends BaseController
{
    /**
     * 获取系统资源使用情况
     *
     * @param Request $request
     * @return Response
     */
    public function getSystemStats(Request $request): Response
    {
        try {
            $systemService = $this->getSystemService();
            $stats = $systemService->getSystemStats();

            // 获取系统信息
            $osName = PHP_OS_FAMILY === 'Darwin' ? 'Darwin' : PHP_OS;
            $osVersion = php_uname('r');
            $uptime = $this->getSystemUptime();

            // 格式化 CPU 频率
            $cpuFrequency = 'Unknown';
            if (file_exists('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                if (preg_match('/cpu MHz\s+:\s+([\d.]+)/', $cpuinfo, $matches)) {
                    $cpuFrequency = round((float)$matches[1] / 1000, 2) . ' GHz';
                }
            } elseif (PHP_OS_FAMILY === 'Darwin') {
                $freq = shell_exec('sysctl -n hw.cpufrequency');
                if ($freq) {
                    $cpuFrequency = round((int)$freq / 1000000000, 2) . ' GHz';
                }
            }

            // 格式化 CPU 负载
            $cpuLoad = implode(', ', array_map(function($v) {
                return number_format($v, 2);
            }, $stats['cpu']['load_average'] ?? [0, 0, 0]));

            // 格式化服务器时间
            $serverTime = gmdate('Y-m-d\TH:i:s\Z');

            return $this->createSuccessJsonResponse([
                'cpu_usage' => (int)round($stats['cpu']['usage'] ?? 0),
                'cpu_cores' => $stats['cpu']['cores'] ?? 0,
                'cpu_frequency' => $cpuFrequency,
                'cpu_load' => $cpuLoad,

                'memory_usage' => (int)round($stats['memory']['usage_percent'] ?? 0),
                'memory_total' => $stats['memory']['total'] ?? 0,
                'memory_used' => $stats['memory']['used'] ?? 0,
                'memory_free' => $stats['memory']['available'] ?? 0,

                'disk_usage' => (int)round($stats['disk']['usage_percent'] ?? 0),
                'disk_total' => $stats['disk']['total'] ?? 0,
                'disk_used' => $stats['disk']['used'] ?? 0,
                'disk_free' => $stats['disk']['free'] ?? 0,

                'network_upload' => $stats['network']['out_speed'] ?? 0,
                'network_download' => $stats['network']['in_speed'] ?? 0,

                'os_name' => $osName,
                'os_version' => $osVersion,
                'uptime' => $uptime,
                'server_time' => $serverTime,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取系统运行时间（秒）
     *
     * @return int
     */
    private function getSystemUptime(): int
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptimeParts = explode(' ', $uptime);
            return (int)($uptimeParts[0] ?? 0);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $bootTime = shell_exec('sysctl -n kern.boottime');
            // macOS boottime output format: { sec = 1234567890, usec = 0 }
            if (preg_match('/sec\s*=\s*(\d+)/', $bootTime, $matches)) {
                return time() - (int)$matches[1];
            }
        }
        return 0;
    }


    /**
     * 获取设备统计信息
     *
     * @param Request $request
     * @return Response
     */
    public function getDeviceStats(Request $request): Response
    {
        try {
            $deviceService = $this->getDeviceService();

            // 设备概览统计
            $totalCount = $deviceService->countDevices([]);
            $onlineCount = $deviceService->countDevices(['status' => 'online']);
            $offlineCount = $totalCount - $onlineCount;

            // 获取所有设备用于详细统计
            $allDevices = $deviceService->searchDevices([], [], 0, 1000);

            // 通道统计
            $totalChannels = 0;
            $onlineChannels = 0;
            $offlineChannels = 0;
            foreach ($allDevices as $device) {
                $channels = $deviceService->getChannelsByDeviceId($device['device_id']);
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
                'offline_count' => $offlineCount,

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
        usort($devices, function($a, $b) {
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
     * 获取CPU使用率
     *
     * @param Request $request
     * @return Response
     */
    public function getCpuUsage(Request $request): Response
    {
        try {

            $cpuStats = $this->getSystemService()->getCpuUsage();

            return $this->createSuccessJsonResponse($cpuStats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取内存使用情况
     *
     * @param Request $request
     * @return Response
     */
    public function getMemoryUsage(Request $request): Response
    {
        try {

            $memoryStats = $this->getSystemService()->getMemoryUsage();

            return $this->createSuccessJsonResponse($memoryStats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取网络统计信息
     *
     * @param Request $request
     * @return Response
     */
    public function getNetworkStats(Request $request): Response
    {
        try {
            $systemService = $this->createService('System:SystemService');

            $networkStats = $this->getSystemService()->getNetworkStats();

            return $this->createSuccessJsonResponse($networkStats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取磁盘IO统计
     *
     * @param Request $request
     * @return Response
     */
    public function getDiskStats(Request $request): Response
    {
        try {

            $diskStats = $this->getSystemService()->getDiskUsage();

            return $this->createSuccessJsonResponse($diskStats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @return \CoreW\Business\Devices\Service\DeviceService
     */
    private function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }


    /**
     * @return SystemService
     */
    protected function getSystemService(): SystemService
    {
        return $this->createService('System:SystemService');
    }

    /**
     * @return ZLMClient
     */
    protected function getZlmClient(): ZLMClient
    {
        return $this->getBiz()->offsetGet('zlm_sdk');
    }
}