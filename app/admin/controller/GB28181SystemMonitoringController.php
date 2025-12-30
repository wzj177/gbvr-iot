<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\System\Service\SystemService;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Request;

/**
 * GB28181 系统监控控制器 - 管理后台
 */
class GB28181SystemMonitoringController extends BaseController
{
    /**
     * 获取设备统计信息
     *
     * @param Request $request
     * @return \support\Response
     */
    public function getDeviceStats(Request $request): \support\Response
    {
        try {
            $deviceService = $this->getDeviceService();

            $totalDevices = $deviceService->countDevices([]);
            $onlineDevices = $deviceService->countDevices(['status' => 'online']);

            // 获取今天的录像数和活跃告警数 (模拟)
            $today = date('Y-m-d');
            $recordingToday = $this->getRecordingService()->countRecordingsByDate($today);
            $activeAlarms = $this->getAlarmService()->countActiveAlarms();

            $stats = [
                'totalDevices' => $totalDevices,
                'onlineDevices' => $onlineDevices,
                'offlineDevices' => $totalDevices - $onlineDevices,
                'recordingToday' => $recordingToday,
                'activeAlarms' => $activeAlarms,
            ];

            return $this->createSuccessJsonResponse($stats);
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
     * @return ZLMClient
     */
    protected function getZlmClient(): ZLMClient
    {
        return $this->getBiz()->offsetGet('zlm_sdk');
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