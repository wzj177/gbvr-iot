<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 录像管理控制器 - 管理后台
 */
class GB28181RecordingController extends BaseController
{
    /**
     * 获取录像列表
     */
    public function index(Request $request)
    {
        $deviceId = $request->get('device_id');
        $channelId = $request->get('channel_id');
        $startTime = $request->get('start_time');
        $endTime = $request->get('end_time');

        if (!$deviceId) {
            return $this->createErrorJsonResponse('缺少device_id参数', 400);
        }

        // 构建查询条件
        $conditions = ['device_id' => $deviceId];
        
        if ($channelId) {
            $conditions['channel_id'] = $channelId;
        }
        
        if ($startTime) {
            $conditions['start_time'] = $startTime;
        }
        
        if ($endTime) {
            $conditions['end_time'] = $endTime;
        }

        // 模拟录像数据（实际项目中应从数据库或录像服务器获取）
        $recordings = $this->getRecordingsByConditions($conditions);
        
        $total = count($recordings);
        list($offset, $limit) = $this->getOffsetAndLimit($request);
        
        $pagedRecordings = array_slice($recordings, $offset, $limit);
        
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $pagedRecordings,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 开始录像
     */
    public function startRecord(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            // 发送开始录像命令到信令网关
            $result = $this->getGb28181Service()->startRecord($deviceId, $channelId);

            if (!$result) {
                return $this->createErrorJsonResponse('发送开始录像请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送开始录像请求异常: ' . $e->getMessage(), 500);
        }

        return $this->createSuccessJsonResponse([
            'message' => '录像已开始',
        ]);
    }

    /**
     * 停止录像
     */
    public function stopRecord(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            // 发送停止录像命令到信令网关
            $result = $this->getGb28181Service()->stopRecord($deviceId, $channelId);

            if (!$result) {
                return $this->createErrorJsonResponse('发送停止录像请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送停止录像请求异常: ' . $e->getMessage(), 500);
        }

        return $this->createSuccessJsonResponse([
            'message' => '录像已停止',
        ]);
    }

    /**
     * 获取抓拍
     */
    public function snapshot(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            // 发送抓拍命令到信令网关
            $result = $this->getGb28181Service()->takeSnapshot($deviceId, $channelId);

            if (!$result) {
                return $this->createErrorJsonResponse('发送抓拍请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送抓拍请求异常: ' . $e->getMessage(), 500);
        }

        // 模拟返回抓拍结果
        $snapshotUrl = "/api/v2/gb28181/channels/snapshot/{$deviceId}/{$channelId}/latest";

        return $this->createSuccessJsonResponse([
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'snapshot_url' => $snapshotUrl,
            'message' => '抓拍成功',
        ]);
    }

    /**
     * 模拟根据条件获取录像数据
     * 
     * @param array $conditions
     * @return array
     */
    private function getRecordingsByConditions(array $conditions): array
    {
        // 这里是模拟数据，实际项目中应从数据库或录像服务器获取真实数据
        $mockRecordings = [
            [
                'id' => 'rec_001',
                'device_id' => $conditions['device_id'],
                'channel_id' => $conditions['channel_id'] ?? '001',
                'start_time' => date('Y-m-d\TH:i:s', strtotime('-1 day')),
                'end_time' => date('Y-m-d\TH:i:s', strtotime('-1 day + 1 hour')),
                'duration' => 3600,
                'file_size' => '120MB',
                'type' => 'motion'
            ],
            [
                'id' => 'rec_002',
                'device_id' => $conditions['device_id'],
                'channel_id' => $conditions['channel_id'] ?? '002',
                'start_time' => date('Y-m-d\TH:i:s', strtotime('-2 hours')),
                'end_time' => date('Y-m-d\TH:i:s', strtotime('-1 hour')),
                'duration' => 3600,
                'file_size' => '150MB',
                'type' => 'manual'
            ],
            [
                'id' => 'rec_003',
                'device_id' => $conditions['device_id'],
                'channel_id' => $conditions['channel_id'] ?? '001',
                'start_time' => date('Y-m-d\TH:i:s', strtotime('-30 minutes')),
                'end_time' => date('Y-m-d\TH:i:s', strtotime('-15 minutes')),
                'duration' => 900,
                'file_size' => '45MB',
                'type' => 'motion'
            ]
        ];

        // 根据条件过滤数据
        $filtered = $mockRecordings;
        
        if (isset($conditions['channel_id'])) {
            $filtered = array_filter($filtered, function($rec) use ($conditions) {
                return $rec['channel_id'] === $conditions['channel_id'];
            });
        }
        
        if (isset($conditions['start_time'])) {
            $filtered = array_filter($filtered, function($rec) use ($conditions) {
                return $rec['start_time'] >= $conditions['start_time'];
            });
        }
        
        if (isset($conditions['end_time'])) {
            $filtered = array_filter($filtered, function($rec) use ($conditions) {
                return $rec['end_time'] <= $conditions['end_time'];
            });
        }

        return array_values($filtered);
    }

    /**
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}