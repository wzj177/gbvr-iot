<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\GB\Gb28181Service;
use support\Request;
use support\Log;

/**
 * GB28181 设备控制控制器 - 管理后台
 *
 * 集中管理所有设备控制类命令：
 * - 远程重启
 * - 录像控制
 * - 布防/撤防
 * - 报警复位
 * - 强制关键帧
 * - 看守位
 * - 拖拽变倍
 * - 设备基础配置
 * - 雨刷控制
 * - 辅助开关
 * - 设备配置查询
 */
class GB28181DeviceControlController extends BaseController
{
    /**
     * 远程重启
     *
     * POST /api/admin/gb28181/device-control/reboot
     */
    public function reboot(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->teleBoot($deviceId, $channelId);
            if (!$result) {
                return $this->createErrorJsonResponse('发送重启命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => '重启命令已发送']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送重启命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 录像控制
     *
     * POST /api/admin/gb28181/device-control/record
     * @param string action: Record / StopRecord
     */
    public function record(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $action = $request->post('action', 'Record');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        if (!in_array($action, ['Record', 'StopRecord'])) {
            return $this->createErrorJsonResponse('action 必须是 Record 或 StopRecord', 400);
        }

        try {
            $result = $this->getGb28181Service()->recordControl($deviceId, $channelId, $action);
            if (!$result) {
                return $this->createErrorJsonResponse('发送录像控制命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => "录像控制命令({$action})已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送录像控制命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 布防/撤防
     *
     * POST /api/admin/gb28181/device-control/guard
     * @param string action: SetGuard / ResetGuard
     */
    public function guard(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $action = $request->post('action', 'SetGuard');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        if (!in_array($action, ['SetGuard', 'ResetGuard'])) {
            return $this->createErrorJsonResponse('action 必须是 SetGuard 或 ResetGuard', 400);
        }

        try {
            $result = $this->getGb28181Service()->guardControl($deviceId, $channelId, $action);
            if (!$result) {
                return $this->createErrorJsonResponse('发送布防/撤防命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => "布防/撤防命令({$action})已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送布防/撤防命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 报警复位
     *
     * POST /api/admin/gb28181/device-control/alarm-reset
     */
    public function alarmReset(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $alarmMethod = $request->post('alarm_method');
        $alarmType = $request->post('alarm_type');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->alarmReset(
                $deviceId,
                $channelId,
                $alarmMethod !== null ? (int)$alarmMethod : null,
                $alarmType !== null ? (int)$alarmType : null
            );
            if (!$result) {
                return $this->createErrorJsonResponse('发送报警复位命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => '报警复位命令已发送']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送报警复位命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 强制关键帧
     *
     * POST /api/admin/gb28181/device-control/iframe
     */
    public function iFrame(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->requestIFrame($deviceId, $channelId);
            if (!$result) {
                return $this->createErrorJsonResponse('发送强制关键帧命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => '强制关键帧命令已发送']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送强制关键帧命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 看守位控制
     *
     * POST /api/admin/gb28181/device-control/home-position
     */
    public function homePosition(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $enabled = $request->post('enabled', 1);
        $resetTime = (int)$request->post('reset_time', 0);
        $presetIndex = (int)$request->post('preset_index', 1);

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->homePosition($deviceId, $channelId, (bool)$enabled, $resetTime, $presetIndex);
            if (!$result) {
                return $this->createErrorJsonResponse('发送看守位命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => '看守位命令已发送']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送看守位命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 拖拽变倍
     *
     * POST /api/admin/gb28181/device-control/drag-zoom
     */
    public function dragZoom(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $type = $request->post('type', 'in'); // in / out

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        if (!in_array($type, ['in', 'out'])) {
            return $this->createErrorJsonResponse('type 必须是 in 或 out', 400);
        }

        $params = [
            'length'      => (int)$request->post('length', 0),
            'width'       => (int)$request->post('width', 0),
            'mid_point_x' => (int)$request->post('mid_point_x', 0),
            'mid_point_y' => (int)$request->post('mid_point_y', 0),
            'length_x'    => (int)$request->post('length_x', 0),
            'length_y'    => (int)$request->post('length_y', 0),
        ];

        try {
            $result = $this->getGb28181Service()->dragZoom($deviceId, $channelId, $type, $params);
            if (!$result) {
                return $this->createErrorJsonResponse('发送拖拽变倍命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => "拖拽变倍({$type})命令已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送拖拽变倍命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 设备基础配置
     *
     * POST /api/admin/gb28181/device-control/config
     */
    public function config(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        $params = [];
        $name = $request->post('name');
        $expiration = $request->post('expiration');
        $heartbeatInterval = $request->post('heartbeat_interval');
        $heartbeatCount = $request->post('heartbeat_count');

        if ($name !== null) $params['name'] = $name;
        if ($expiration !== null) $params['expiration'] = (int)$expiration;
        if ($heartbeatInterval !== null) $params['heartbeat_interval'] = (int)$heartbeatInterval;
        if ($heartbeatCount !== null) $params['heartbeat_count'] = (int)$heartbeatCount;

        if (empty($params)) {
            return $this->createErrorJsonResponse('至少需要提供一个配置参数(name, expiration, heartbeat_interval, heartbeat_count)', 400);
        }

        try {
            $result = $this->getGb28181Service()->deviceConfig($deviceId, $channelId, $params);
            if (!$result) {
                return $this->createErrorJsonResponse('发送设备配置命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => '设备配置命令已发送']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送设备配置命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 雨刷控制
     *
     * POST /api/admin/gb28181/device-control/wiper
     */
    public function wiper(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $on = $request->post('on', true);

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->wiperControl($deviceId, $channelId, (bool)$on);
            if (!$result) {
                return $this->createErrorJsonResponse('发送雨刷控制命令失败', 500);
            }
            $action = $on ? '开启' : '关闭';
            return $this->createSuccessJsonResponse(['message' => "雨刷{$action}命令已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送雨刷控制命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 辅助开关控制
     *
     * POST /api/admin/gb28181/device-control/aux-switch
     */
    public function auxSwitch(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $switchId = (int)$request->post('switch_id', 1);
        $on = $request->post('on', true);

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->auxControl($deviceId, $channelId, $switchId, (bool)$on);
            if (!$result) {
                return $this->createErrorJsonResponse('发送辅助开关命令失败', 500);
            }
            $action = $on ? '打开' : '关闭';
            return $this->createSuccessJsonResponse(['message' => "辅助开关({$switchId}){$action}命令已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送辅助开关命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 设备配置查询
     *
     * POST /api/admin/gb28181/device-control/config-query
     */
    public function configQuery(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $configType = $request->post('config_type', 'BasicParam');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少必要参数(device_id, channel_id)', 400);
        }

        try {
            $result = $this->getGb28181Service()->configDownload($deviceId, $channelId, $configType);
            if (!$result) {
                return $this->createErrorJsonResponse('发送配置查询命令失败', 500);
            }
            return $this->createSuccessJsonResponse(['message' => "配置查询命令({$configType})已发送"]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送配置查询命令异常: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return Gb28181Service
     */
    private function getGb28181Service() : Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }
}
