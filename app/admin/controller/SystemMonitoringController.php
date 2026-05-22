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
    public function getSystemStats(Request $request) : Response
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
                if (preg_match('/cpu MHz\s*:\s*([\d.]+)/', $cpuinfo, $matches)) {
                    $cpuFrequency = round((float)$matches[1] / 1000, 2) . ' GHz';
                }
            } else if (PHP_OS_FAMILY === 'Darwin') {
                // macOS: try sysctl hw.cpufrequency first
                $freq = @shell_exec('sysctl -n hw.cpufrequency 2>/dev/null');
                if ($freq && trim($freq) !== '') {
                    $cpuFrequency = round((int)trim($freq) / 1000000000, 2) . ' GHz';
                } else {
                    // Fallback: try getting from hw.tbfrequency (timebase frequency)
                    $tbfreq = @shell_exec('sysctl -n hw.tbfrequency 2>/dev/null');
                    if ($tbfreq && trim($tbfreq) !== '') {
                        // Timebase is usually close to CPU frequency
                        $cpuFrequency = round((int)trim($tbfreq) / 1000000000, 2) . ' GHz';
                    }
                }
            }

            // 格式化 CPU 负载
            $loadAvg = $stats['cpu']['load_average'] ?? [0, 0, 0];
            $cpuCores = $stats['cpu']['cores'] ?? 1;
            $serverTime = gmdate('Y-m-d\TH:i:s\Z');

            return $this->createSuccessJsonResponse([
                'cpu_usage'     => (int)round($stats['cpu']['usage'] ?? 0),
                'cpu_cores'     => $cpuCores,
                'cpu_frequency' => $cpuFrequency,
                'cpu_load'      => [
                    'load1'          => round($loadAvg[0], 2),
                    'load5'          => round($loadAvg[1], 2),
                    'load15'         => round($loadAvg[2], 2),
                    // 负载百分比（相对于核心数）
                    'load1_percent'  => $cpuCores > 0 ? round(($loadAvg[0] / $cpuCores) * 100, 1) : 0,
                    'load5_percent'  => $cpuCores > 0 ? round(($loadAvg[1] / $cpuCores) * 100, 1) : 0,
                    'load15_percent' => $cpuCores > 0 ? round(($loadAvg[2] / $cpuCores) * 100, 1) : 0,
                ],

                'memory_usage' => (int)round($stats['memory']['usage_percent'] ?? 0),
                'memory_total' => $stats['memory']['total'] ?? 0,
                'memory_used'  => $stats['memory']['used'] ?? 0,
                'memory_free'  => $stats['memory']['available'] ?? 0,

                'disk_usage' => (int)round($stats['disk']['usage_percent'] ?? 0),
                'disk_total' => $stats['disk']['total'] ?? 0,
                'disk_used'  => $stats['disk']['used'] ?? 0,
                'disk_free'  => $stats['disk']['free'] ?? 0,

                'network_upload'   => $stats['network']['out_speed'] ?? 0,
                'network_download' => $stats['network']['in_speed'] ?? 0,

                'os_name'     => $osName,
                'os_version'  => $osVersion,
                'uptime'      => $uptime,
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
    private function getSystemUptime() : int
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptimeParts = explode(' ', $uptime);
            return (int)($uptimeParts[0] ?? 0);
        } else if (PHP_OS_FAMILY === 'Darwin') {
            $bootTime = shell_exec('sysctl -n kern.boottime');
            // macOS boottime output format: { sec = 1234567890, usec = 0 }
            if (preg_match('/sec\s*=\s*(\d+)/', $bootTime, $matches)) {
                return time() - (int)$matches[1];
            }
        }
        return 0;
    }


    /**
     * 获取CPU使用率
     *
     * @param Request $request
     * @return Response
     */
    public function getCpuUsage(Request $request) : Response
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
    public function getMemoryUsage(Request $request) : Response
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
    public function getNetworkStats(Request $request) : Response
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
    public function getDiskStats(Request $request) : Response
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
    protected function getSystemService() : SystemService
    {
        return $this->createService('System:SystemService');
    }
}