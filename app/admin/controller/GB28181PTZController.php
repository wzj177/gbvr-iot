<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use support\Request;

/**
 * GB28181 PTZ控制控制器 - 管理后台
 */
class GB28181PTZController extends BaseController
{
    /**
     * PTZ控制
     */
    public function control(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $command = $request->post('command'); // up, down, left, right, zoom_in, zoom_out, stop
        $speed = $request->post('speed', 5); // 1-255

        if (!$deviceId || !$channelId || !$command) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        // 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->ptzControl($deviceId, $channelId, $command, (int)$speed);

            if (!$result) {
                return $this->createErrorJsonResponse('发送PTZ控制请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送PTZ控制请求异常: ' . $e->getMessage(), 500);
        }

        return $this->createSuccessJsonResponse([
            'message' => 'PTZ命令已发送',
        ]);
    }

    /**
     * 获取预置位列表
     */
    public function getPresetList(Request $request)
    {
        $deviceId = $request->get('device_id');
        $channelId = $request->get('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        try {
            $presets = $this->getDeviceService()->getPresetList($deviceId, $channelId);
            return $this->createSuccessJsonResponse($presets);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('获取预置位列表失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 设置预置位
     */
    public function setPreset(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $value = (int)$request->post('value');
        $name = $request->post('name', '');

        if (!$deviceId || !$channelId || !$value) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        if ($value < 1 || $value > 255) {
            return $this->createErrorJsonResponse('预置位编号必须在1-255之间', 400);
        }

        try {
            $preset = $this->getDeviceService()->setPreset($deviceId, $channelId, $value, $name);
            return $this->createSuccessJsonResponse($preset, '预置位设置成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('设置预置位失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 调用预置位
     */
    public function callPreset(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $value = (int)$request->post('value');

        if (!$deviceId || !$channelId || !$value) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        if ($value < 1 || $value > 255) {
            return $this->createErrorJsonResponse('预置位编号必须在1-255之间', 400);
        }

        try {
            $result = $this->getDeviceService()->callPreset($deviceId, $channelId, $value);
            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '预置位调用成功']);
            } else {
                return $this->createErrorJsonResponse('预置位调用失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('调用预置位失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 删除预置位
     */
    public function deletePreset(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $value = (int)$request->post('value');

        if (!$deviceId || !$channelId || !$value) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        if ($value < 1 || $value > 255) {
            return $this->createErrorJsonResponse('预置位编号必须在1-255之间', 400);
        }

        try {
            $result = $this->getDeviceService()->deletePreset($deviceId, $channelId, $value);
            if ($result) {
                return $this->createSuccessJsonResponse(['message' => '预置位删除成功']);
            } else {
                return $this->createErrorJsonResponse('预置位删除失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('删除预置位失败: ' . $e->getMessage(), 500);
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
        return $this->getBiz()->offsetGet('gb28181_service');
    }
}