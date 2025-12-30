<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 通道管理控制器 - 管理后台
 */
class GB28181ChannelController extends BaseController
{
    /**
     * 获取设备通道列表
     */
    public function index(Request $request, $deviceId)
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 构建查询条件
        $conditions = ['device_id' => $deviceId];
        
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }
        
        if ($request->get('keyword')) {
            $conditions['keyword'] = $request->get('keyword');
        }

        $total = $this->getDeviceService()->countChannels($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $channels = $this->getDeviceService()->searchChannels($conditions, ['id' => 'DESC'], $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $channels,
            'device_id' => $deviceId,
            'device_name' => $device['device_name'],
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取通道详情
     */
    public function show(Request $request, $deviceId, $channelId)
    {
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        return $this->createSuccessJsonResponse($channel);
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}