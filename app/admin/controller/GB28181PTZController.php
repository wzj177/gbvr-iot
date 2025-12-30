<?php

namespace app\admin\controller;

use app\admin\BaseController;
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
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }
}