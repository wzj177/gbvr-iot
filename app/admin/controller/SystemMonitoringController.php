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
            // 使用SystemService获取系统资源使用情况

            $stats = $this->getSystemService()->getSystemStats();

            return $this->createSuccessJsonResponse($stats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
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

            $totalDevices = $deviceService->countDevices([]);
            $onlineDevices = $deviceService->countDevices(['status' => 'online']);

            // 模拟录像和报警数据（实际情况下应从数据库获取）
            $stats = [
                'totalDevices' => $totalDevices,
                'onlineDevices' => $onlineDevices,
                'offlineDevices' => $totalDevices - $onlineDevices,
                'recordingToday' => rand(100, 500), // 模拟今天的录像数
                'activeAlarms' => rand(1, 10),     // 模拟活跃告警数
            ];

            return $this->createSuccessJsonResponse($stats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取ZLMediaKit状态
     *
     * @param Request $request
     * @return Response
     */
    public function getZLMediaKitStats(Request $request): Response
    {
        try {

            // 获取 ZLM 版本信息
            $versionResp = $this->getZlmClient()->getVersion();

            // 获取服务器配置
            $configResp = $this->getZlmClient()->getServerConfig();

            // 获取线程负载
            $loadResp = $this->getZlmClient()->getThreadsLoad();

            $stats = [
                'version' => $versionResp['data']['branchName'] ?? 'Unknown',
                'config' => $configResp['data'] ?? [],
                'thread_load' => $loadResp['data'] ?? [],
                'streams_count' => rand(10, 100), // 模拟流数量
                'connections_count' => rand(50, 200), // 模拟连接数
            ];

            return $this->createSuccessJsonResponse($stats);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
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