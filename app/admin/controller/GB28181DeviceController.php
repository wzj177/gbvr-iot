<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\DeviceFilter;
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
            'list' => DeviceFilter::publicList($devices),
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
            // 基础配置
            'show_name',       // 自定义名称
            'rtp_trans_mode',  // RTP传输模式：0=UDP，1=TCP被动，2=TCP主动
            'enabled',

            // 行政区域
            'province_id',     // 省份代码（6位行政区划码）
            'city_id',         // 城市代码
            'county_id',       // 区县代码
            'custom_lat',
            'custom_lng',

            // 订阅配置
            'subscribe_catalog',  // 是否订阅目录变更
            'subscribe_alarm',    // 是否订阅报警事件
            'subscribe_position', // 是否订阅位置上报
            'subscribe_ptz',      // 是否订阅PTZ控制反馈(2022)
            'subscribe_expires',  // 订阅有效期（秒）
            'position_interval',  // 位置上报间隔（秒）

            // 通道更新配置
            'catalog_interval',  // 通道目录更新周期（秒），0=禁用轮询

            // 字符集和码流
            'charset',       // 设备XML字符集: gb2312, utf8
            'stream_index',  // 码流索引: auto=自动, 0=主码流, 1=子码流

            // 通道过滤
            'filter_channel_types',  // 过滤的通道类型列表，如[134,135]
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

        // 验证 charset
        if (isset($updateData['charset'])) {
            if (!in_array($updateData['charset'], ['gb2312', 'utf8'])) {
                return $this->createErrorJsonResponse('字符集无效，必须为 gb2312 或 utf8', 400);
            }
        }

        // 验证 stream_index
        if (isset($updateData['stream_index'])) {
            if (!in_array($updateData['stream_index'], ['auto', '0', '1'])) {
                return $this->createErrorJsonResponse('码流索引无效，必须为 auto、0 或 1', 400);
            }
        }

        // 验证布尔字段
        $booleanFields = ['subscribe_catalog', 'subscribe_alarm', 'subscribe_position', 'subscribe_ptz', 'enabled'];
        foreach ($booleanFields as $field) {
            if (isset($updateData[$field])) {
                $updateData[$field] = (int)$updateData[$field];
            }
        }

        // 验证整数字段
        $intFields = ['subscribe_expires', 'position_interval', 'catalog_interval'];
        foreach ($intFields as $field) {
            if (isset($updateData[$field])) {
                $updateData[$field] = (int)$updateData[$field];
                if ($updateData[$field] < 0) {
                    return $this->createErrorJsonResponse("{$field} 必须大于等于 0", 400);
                }
            }
        }

        // 验证 filter_channel_types (JSON数组)
        if (isset($updateData['filter_channel_types'])) {
            if (is_array($updateData['filter_channel_types'])) {
                $updateData['filter_channel_types'] = json_encode($updateData['filter_channel_types']);
            } elseif (!is_null($updateData['filter_channel_types']) && $updateData['filter_channel_types'] !== '') {
                // 尝试解析 JSON 字符串
                $decoded = json_decode($updateData['filter_channel_types'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->createErrorJsonResponse('filter_channel_types 格式错误，必须是 JSON 数组', 400);
                }
                $updateData['filter_channel_types'] = json_encode($decoded);
            } else {
                $updateData['filter_channel_types'] = null;
            }
        }

        try {
            $this->getDeviceService()->updateDeviceExtendInfo($id, $updateData);

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
     * 获取设备树形数据
     */
    public function tree(Request $request)
    {
        $treeType = $request->get('tree_type', 'dc');

        if (!in_array($treeType, ['dc', 'area'])) {
            return $this->createErrorJsonResponse('tree_type 参数无效，必须是 dc 或 area');
        }

        try {
            $tree = $this->getDeviceService()->getDeviceTree($treeType);

            return $this->createSuccessJsonResponse($tree);
        } catch (\Exception $e) {
            Log::error('Get device tree failed', [
                'tree_type' => $treeType,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('获取设备树失败: ' . $e->getMessage(), 500);
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