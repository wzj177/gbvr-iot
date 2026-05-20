<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Enums\DeviceCategoryEnum;
use support\Request;

/**
 * GB28181 设备分类管理控制器
 */
class GB28181DeviceCategoryController extends BaseController
{
    /**
     * 获取所有设备分类选项
     * GET /api/admin/gb28181/device-categories/options
     */
    public function options(Request $request)
    {
        return $this->createSuccessJsonResponse([
            'options' => DeviceCategoryEnum::options(),
            'map'     => DeviceCategoryEnum::map(),
        ]);
    }

    /**
     * 更新设备分类
     * PUT /api/admin/gb28181/devices/{deviceId}/category
     * Body: { "category_code": 111 }  // 传null则自动从device_id解析
     */
    public function update(Request $request, string $deviceId)
    {
        $categoryCode = $request->post('category_code');

        // 验证分类编码
        if ($categoryCode !== null) {
            $category = DeviceCategoryEnum::tryFrom((int)$categoryCode);
            if (!$category) {
                return $this->createErrorJsonResponse('无效的设备分类编码');
            }
        }

        $result = $this->getDeviceService()->updateDeviceCategory(
            $deviceId,
            $categoryCode !== null ? (int)$categoryCode : null
        );

        if (!$result) {
            return $this->createErrorJsonResponse('设备不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse(null, '设备分类更新成功');
    }

    /**
     * 获取设备分类统计
     * GET /api/admin/gb28181/device-categories/statistics
     */
    public function statistics(Request $request)
    {
        $devices = $this->getDeviceService()->searchDevices([], [], 0, PHP_INT_MAX, ['device_id', 'device_category']);

        // 统计各分类设备数量
        $stats = [];
        $uncategorized = 0;

        foreach ($devices as $device) {
            if (empty($device['device_category'])) {
                $uncategorized++;
                continue;
            }

            $category = DeviceCategoryEnum::tryFrom((int)$device['device_category']);
            if ($category) {
                $categoryKey = $category->value;
                if (!isset($stats[$categoryKey])) {
                    $stats[$categoryKey] = [
                        'code'      => $category->value,
                        'name'      => $category->label(),
                        'icon'      => $category->icon(),
                        'count'     => 0,
                        'is_mobile' => $category->isMobileDevice(),
                    ];
                }
                $stats[$categoryKey]['count']++;
            } else {
                $uncategorized++;
            }
        }

        return $this->createSuccessJsonResponse([
            'categories'    => array_values($stats),
            'uncategorized' => $uncategorized,
            'total'         => count($devices),
        ]);
    }

    /**
     * @return \CoreW\Business\Devices\Service\DeviceService
     */
    private function getDeviceService() : \CoreW\Business\Devices\Service\DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}
