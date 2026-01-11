<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use support\Request;

/**
 * GB28181 电子地图控制器 - 管理后台
 */
class GB28181MapController extends BaseController
{
    /**
     * 获取设备位置信息
     */
    public function getDevices(Request $request)
    {
        $deviceIds = $request->get('device_ids');
        $status = $request->get('status');

        $conditions = [];
        if ($status) {
            $conditions['status'] = $status;
        }

        // 如果提供了设备ID列表，则只查询这些设备
        if ($deviceIds) {
            if (is_string($deviceIds)) {
                $deviceIds = explode(',', $deviceIds);
            }
            $conditions['device_ids'] = $deviceIds;
        }

        // 获取设备列表（实际项目中应从数据库获取包含位置信息的设备）
        $devices = $this->getDevicesWithLocation($conditions);

        return $this->createSuccessJsonResponse($devices);
    }

    /**
     * 更新设备位置
     */
    public function updatePosition(Request $request, $id)
    {
        $latitude = $request->put('latitude');
        $longitude = $request->put('longitude');
        $address = $request->put('address');

        if ($latitude === null || $longitude === null) {
            return $this->createErrorJsonResponse('缺少latitude或longitude参数', 400);
        }

        // 验证坐标范围
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->createErrorJsonResponse('坐标范围不正确', 400);
        }

        try {
            // 更新设备位置信息
            $result = $this->getDeviceService()->updateDeviceLocation($id, [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $address
            ]);

            if (!$result) {
                return $this->createErrorJsonResponse('更新设备位置失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('更新设备位置异常: ' . $e->getMessage(), 500);
        }

        return $this->createSuccessJsonResponse([
            'message' => '设备位置已更新',
        ]);
    }

    /**
     * 模拟获取带位置信息的设备列表
     * 
     * @param array $conditions
     * @return array
     */
    private function getDevicesWithLocation(array $conditions): array
    {
        // 模拟设备数据，实际项目中应从数据库获取
        $mockDevices = [
            [
                'id' => '34020000001320000001',
                'name' => '主入口摄像头',
                'lng' => 116.397428,
                'lat' => 39.90923,
                'online' => true,
                'status' => 'normal',
                'address' => '北京市东城区天安门广场'
            ],
            [
                'id' => '34020000001320000002',
                'name' => '后门摄像头',
                'lng' => 116.407428,
                'lat' => 39.91923,
                'online' => true,
                'status' => 'motion_detect',
                'address' => '北京市东城区故宫博物院'
            ],
            [
                'id' => '34020000001320000003',
                'name' => '停车场摄像头',
                'lng' => 116.417428,
                'lat' => 39.92923,
                'online' => false,
                'status' => 'alarm',
                'address' => '北京市东城区王府井大街'
            ]
        ];

        // 根据条件过滤数据
        $filtered = $mockDevices;
        
        if (isset($conditions['status'])) {
            $filtered = array_filter($filtered, function($device) use ($conditions) {
                return $device['status'] === $conditions['status'];
            });
        }
        
        if (isset($conditions['device_ids']) && is_array($conditions['device_ids'])) {
            $filtered = array_filter($filtered, function($device) use ($conditions) {
                return in_array($device['id'], $conditions['device_ids']);
            });
        }

        return array_values($filtered);
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}