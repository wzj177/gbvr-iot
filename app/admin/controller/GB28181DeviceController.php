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
            'summary' => $this->getDeviceService()->summaryDevices($conditions),
            'list' => $devices,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取设备详情
     */
    public function show(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 获取通道列表
        $channels = $this->getDeviceService()->getChannelsByDeviceId($device['device_id']);
        $device['channels'] = $channels;

        return $this->createSuccessJsonResponse($device);
    }

    /**
     * 删除设备
     */
    public function destroy(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        // 删除设备和通道
        try {
            $this->getDeviceService()->deleteDeviceById($id);

            return $this->createSuccessJsonResponse([
                'message' => '设备已删除',
            ]);
        } catch (\Exception $e) {
            $this->getLogService()->error('GB28181', 'delete_device', "删除设备失败，{$e->getMessage()}", [
                'device_id' => $device['device_id'],
            ]);

            return $this->createErrorJsonResponse('删除设备失败', 500);
        }
    }

    /**
     * 查询设备目录（发送命令到信令网关）
     */
    public function queryCatalog(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        if ($device['status'] !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }

        // 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->queryCatalog($device['device_id']);

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
     * 更新设备信息
     */
    public function update(Request $request, $id)
    {
        $device = $this->getDeviceService()->getDevicesById($id);

        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }

        $data = $request->post();
        $allowedFields = [
            'show_name',       // 自定义名称
            'rtp_trans_mode',  // RTP传输模式：0=UDP，1=TCP被动，2=TCP主动
            'province_id',     // 省份代码（6位行政区划码）
            'city_id',         // 城市代码
            'county_id',       // 区县代码
            'custom_lat',
            'custom_lng',
            'enabled'
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        // 验证 rtp_trans_mode
        if (isset($updateData['rtp_trans_mode'])) {
            $updateData['rtp_trans_mode'] = (int)$updateData['rtp_trans_mode'];
            if (!in_array($updateData['rtp_trans_mode'], [0, 1, 2])) {
                return $this->createErrorJsonResponse('RTP传输模式无效，必须为 0(UDP)、1(TCP被动) 或 2(TCP主动)', 400);
            }
        }

        try {
            $this->getDeviceService()->updateDevice($id, $updateData);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            Log::error('Update device failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('更新设备失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 批量设置设备地区
     */
    public function batchUpdateArea(Request $request)
    {
        $ids = $request->post('ids', []);
        $provinceId = $request->post('province_id', '');
        $cityId = $request->post('city_id', '');
        $countyId = $request->post('county_id', '');

        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要设置的设备');
        }

        $updateData = [];
        if (!empty($provinceId)) {
            $updateData['province_id'] = $provinceId;
        }
        if (!empty($cityId)) {
            $updateData['city_id'] = $cityId;
        }
        if (!empty($countyId)) {
            $updateData['county_id'] = $countyId;
        }

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('请至少设置一个地区参数');
        }

        try {
            $successCount = 0;
            foreach ($ids as $id) {
                $device = $this->getDeviceService()->getDevicesById($id);
                if ($device) {
                    $this->getDeviceService()->updateDevice($id, $updateData);
                    $successCount++;
                }
            }

            $this->getLogService()->info('gb28181', 'batch_update_area', "批量设置设备地区，成功: {$successCount}个", [
                'ids' => $ids,
                'updateData' => $updateData,
            ]);

            return $this->createSuccessJsonResponse([
                'successCount' => $successCount,
                'message' => "成功设置 {$successCount} 个设备的地区信息",
            ]);
        } catch (\Exception $e) {
            Log::error('Batch update device area failed', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('批量设置地区失败: ' . $e->getMessage(), 500);
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
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }
}