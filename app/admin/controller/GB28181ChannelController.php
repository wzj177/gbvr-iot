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
    public function index(Request $request)
    {


        // 构建查询条件
        $conditions = [];
        
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        // device_id
        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
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
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取通道详情
     */
    public function show(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        return $this->createSuccessJsonResponse($channel);
    }

    /**
     * 更新通道信息
     */
    public function update(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        $data = $request->post();
        $allowedFields = [
            'show_name',    // 显示名称
            'origin_code',  // 级联编号
            'custom_lat',   // 自填纬度
            'custom_lng',   // 自填经度
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        // 验证经纬度格式（如果提供）
        if (isset($updateData['custom_lat'])) {
            $lat = (float)$updateData['custom_lat'];
            if ($lat < -90 || $lat > 90) {
                return $this->createErrorJsonResponse('纬度必须在 -90 到 90 之间', 400);
            }
        }

        if (isset($updateData['custom_lng'])) {
            $lng = (float)$updateData['custom_lng'];
            if ($lng < -180 || $lng > 180) {
                return $this->createErrorJsonResponse('经度必须在 -180 到 180 之间', 400);
            }
        }

        try {
            $this->getDeviceService()->updateChannel($id, $updateData);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            \support\Log::error('Update channel failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('更新通道失败: ' . $e->getMessage(), 500);
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