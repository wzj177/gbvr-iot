<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use support\Log;
use support\Redis;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\Paginator;

/**
 * GB28181 设备管理控制器 - 管理后台
 */
class GB28181DeviceController extends BaseController
{
    /**
     * 获取设备列表
     */
    public function index(Request $request)
    {
        $conditions = [];
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        $total = $this->getDeviceService()->countDevices($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $devices = $this->getDeviceService()->searchDevices($conditions, ['id' => 'DESC'], $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $devices,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取设备详情
     */
    public function show(Request $request, $deviceId)
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 获取通道列表
        $channels = $this->getDeviceService()->getChannelsByDeviceId($deviceId);
        $device['channels'] = $channels;

        return $this->createSuccessJsonResponse($device);
    }

    /**
     * 删除设备
     */
    public function destroy(Request $request, $deviceId)
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 删除设备和通道
        try {
            $this->getDeviceService()->deleteDeviceById($device['id']);

            return $this->createSuccessJsonResponse([
                'message' => '设备已删除',
            ]);
        } catch (\Exception $e) {
            $this->getLogService()->error('GB28181', 'delete_device', "删除设备失败，{$e->getMessage()}", [
                'device_id' => $deviceId,
            ]);

            return $this->createErrorJsonResponse('删除设备失败', 500);
        }
    }

    /**
     * 查询设备目录（发送命令到信令网关）
     */
    public function queryCatalog(Request $request, $deviceId)
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        if ($device['status'] !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }

        // 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->queryCatalog($deviceId);

            if (!$result) {
                return $this->createErrorJsonResponse('发送目录查询请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送目录查询请求异常: ' . $e->getMessage(), 500);
        }

        Log::channel('sip')->info('Query catalog command sent', [
            'device_id' => $deviceId,
        ]);

        return $this->createSuccessJsonResponse([
            'message' => '目录查询命令已发送，请等待设备响应',
        ]);
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }
}