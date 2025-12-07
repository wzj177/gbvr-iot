<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;
use support\Redis;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\Paginator;

/**
 * GB28181 设备管理控制器
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
     * 拉取在线设备列表（用于信令网关启动时恢复设备状态）
     *
     * @param Request $request
     * @return \support\Response
     */
    public function pullOnLineList(Request $request): \support\Response
    {
        $devices = $this->getDeviceService()->searchDevices(
            [
                'status' => DeviceStatusEnum::ONLINE->value
            ],
            ['id' => 'ASC'],
            0,
            PHP_INT_MAX,
            ['device_id', 'ip', 'port', 'from_uri', 'user_agent', 'registered_at', 'last_heartbeat_at', 'expires']
        );

        $deviceIds = ArrayToolkit::column($devices, 'device_id');

        $channels = $this->getDeviceService()->searchChannels([
            'device_ids' => $deviceIds
        ], [], 0, PHP_INT_MAX);

        $channelsGrouped = ArrayToolkit::group($channels, 'device_id');
        unset($channels);

        $result = [];
        foreach ($devices as $device) {
            $result[] = [
                'device_id' => $device['device_id'],
                'uri' => $device['from_uri'],
                'ip' => $device['ip'],
                'port' => $device['port'],
                'user_agent' => $device['user_agent'],
                'registered_at' => strtotime($device['registered_at']),
                'timestamp' => strtotime($device['last_heartbeat_at']),
                'expires' => $device['expires'],
                'channels' => $channelsGrouped[$device['device_id']] ?? []
            ];
        }

        return $this->createSuccessJsonResponse($result);
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
     * 获取设备通道列表
     */
    public function channels(Request $request, $deviceId)
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        $channels = $this->getDeviceService()->getChannelsByDeviceId($deviceId);
        
        return $this->createSuccessJsonResponse([
            'device_id' => $deviceId,
            'device_name' => $device['device_name'],
            'channels' => $channels,
        ]);
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
        
        if ($device->status !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }
        
        // 发送命令到Redis，信令网关订阅
        $requestId = uniqid('catalog_');
//        Redis::publish('gb28181:commands', json_encode([
//            'action' => 'query_catalog',
//            'device_id' => $deviceId,
//            'request_id' => $requestId,
//            'timestamp' => time(),
//        ]));
        $this->getBiz()->offsetGet('gb28181_gateway_sdk')->sendCommand($deviceId, 'query_catalog', [
            'request_id' => $requestId,
        ]);
        
        Log::channel('sip')->info('Query catalog command sent', [
            'device_id' => $deviceId,
            'request_id' => $requestId,
        ]);
        
        return $this->createSuccessJsonResponse([
            'request_id' => $requestId,
            'message' => '目录查询命令已发送，请等待设备响应',
        ]);
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

            return $this->createErrorJsonResponse('删除设备失败', );
        }
    }


    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}
