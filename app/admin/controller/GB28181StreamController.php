<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Traits\GB28181StreamTrait;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Service\RecordTaskService;
use support\Request;

/**
 * GB28181 视频流控制器 - 管理后台
 */
class GB28181StreamController extends BaseController
{
    use GB28181StreamTrait;

    /**
     * 开始实时视频
     */
    public function startLive(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            // 验证设备和通道
            ['device' => $device, 'channel' => $channel] = $this->validateDeviceAndChannel($deviceId, $channelId);

            // 执行开始直播核心逻辑
            $result = $this->startLiveVideoCore($device, $channel);
            $playUrls = $this->getPlayUrlsCore($channel);

            return $this->createSuccessJsonResponse([
                ...$result,
                'play_urls' => $playUrls,
                'message' => '实时视频开始',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 停止实时视频
     */
    public function stopLive(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            $this->stopLiveVideoCore($channel);

            return $this->createSuccessJsonResponse([
                'message' => '实时视频已停止',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 获取播放地址
     */
    public function getPlayUrls(Request $request)
    {
        $deviceId = $request->get('device_id');
        $channelId = $request->get('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            if ($channel['status'] !== 'streaming') {
                return $this->createErrorJsonResponse('通道未在推流', 400);
            }

            $playUrls = $this->getPlayUrlsCore($channel);

            return $this->createSuccessJsonResponse([
                'stream_id' => $channel['stream_id'],
                'play_urls' => $playUrls,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), $e->getCode() ?? 500);
        }
    }



    /**
     * 开始录像回放
     */
    public function startPlayback(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $startTime = $request->post('start_time'); // 2024-01-01T00:00:00
        $endTime = $request->post('end_time');

        if (!$deviceId || !$channelId || !$startTime || !$endTime) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        // 验证时间格式
        if (!$this->validateTimeFormat($startTime) || !$this->validateTimeFormat($endTime)) {
            return $this->createErrorJsonResponse('时间格式错误，应为: 2024-01-01T00:00:00', 400);
        }

        try {
            $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

            if (!$device) {
                return $this->createErrorJsonResponse('设备不存在', 404);
            }

            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            $result = $this->startPlaybackCore($device, $channel, $startTime, $endTime);

            $playUrls = $this->getPlayUrlsCore($channel, $result['stream_id']);

            return $this->createSuccessJsonResponse([
                ...$result,
                'play_urls' => $playUrls,
                'message' => '录像回放开始',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    public function stopPlayback(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $streamId = $request->post('stream_id');
        if (!$deviceId || !$channelId || !$streamId) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        };
        try {
            $result = $this->stopPlaybackCore($deviceId, $channelId, $streamId);

            return $this->createSuccessJsonResponse([
                'message' => '录像回放已停止',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 录像回放控制（倍速、暂停、拖动）
     * 
     * 支持的操作：
     * - play: 正常播放（恢复）
     * - pause: 暂停
     * - fast_forward: 快进（2倍速/4倍速）
     * - slow_forward: 慢放（0.5倍速）
     * - seek: 拖动到指定时间
     * - scale: 缩放（数字缩放）
     * 
     * POST 参数：
     * - device_id: 设备ID
     * - channel_id: 通道ID
     * - action: 操作类型
     * - speed: 倍速（1-4，仅快进/慢放需要）
     * - seek_time: 拖动时间（2024-01-01T10:30:00，仅 seek 需要）
     * - scale: 缩放比例（仅 scale 需要）
     */
    public function playbackControl(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在');
        }

        $streamId = $request->post('stream_id');
        if (!$streamId) {
            return $this->createErrorJsonResponse('缺少参数stream_id');
        }

        $action = $request->post('action');
        $speed = $request->post('speed', 1);
        $seekTime = $request->post('seek_time');
        $scale = $request->post('scale', 1.0);

        if (!$action) {
            return $this->createErrorJsonResponse('缺少参数device_id、channel_id或action');
        }

        // 验证 action
        $validActions = ['play', 'pause', 'seek', 'scale', 'teardown'];
        if (!in_array($action, $validActions)) {
            return $this->createErrorJsonResponse('无效的action参数，支持：' . implode(', ', $validActions), 400);
        }

        // seek 必须提供 seek_time
        if ($action === 'seek' && !$seekTime) {
            return $this->createErrorJsonResponse('seek 操作需要提供 seek_time 参数', 400);
        }

        if ($action === 'scale' && $scale == 1.0) {
            $action = 'play';
        }

        try {
            $result = $this->playbackControlCore($channel['device_id'], $channel['channel_id'], $streamId, $action, $speed, $seekTime, $scale);

            return $this->createSuccessJsonResponse([
                'message' => '回放控制命令已发送',
                'action' => $action,
                'speed' => $speed,
                'stream_id' => $streamId,
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 录像下载
     * 
     * 流程：
     * 1. 发送 INVITE (session_name = 'Download')
     * 2. 设备推流到 ZLM
     * 3. ZLM 录制为文件
     * 4. 返回文件下载地址
     * 
     * POST 参数：
     * - device_id: 设备ID
     * - channel_id: 通道ID
     * - start_time: 开始时间（2024-01-01T00:00:00）
     * - end_time: 结束时间
     * - download_speed: 下载倍速（1-4，可选，默认1）
     */
    public function playbackDownload(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在');
        }

        $startTime = $request->post('start_time');
        $endTime = $request->post('end_time');
        $downloadSpeed = $request->post('download_speed', 1);
        $force = $request->post('force', 0);

        if (!$startTime || !$endTime) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        // 验证时间格式
        if (!$this->validateTimeFormat($startTime) || !$this->validateTimeFormat($endTime)) {
            return $this->createErrorJsonResponse('时间格式错误，需要ISO8601格式：2024-01-01T00:00:00', 400);
        }

        try {
            $startTimestamp = strtotime($startTime);
            $endTimestamp = strtotime($endTime);
            $crcStr = crc32($startTimestamp . '_' . $endTimestamp);
            $streamId = $channel['device_id'] . '_' . $channel['channel_id'] . '_download_' . $crcStr;

            // 根据 stream_id 查找已有任务（在有效时间范围内）
            $existingTask = $this->getRecordTaskService()->getDownloadTaskByStreamId($streamId);

            if ($existingTask) {
                $status = $existingTask['status'];

                // 进行中的任务
                if (in_array($status, ['inviting', 'wait_stream', 'recording'])) {
                    // 强制模式：先停止并删除旧任务
                    if ($force) {
                        $this->getRecordTaskService()->cancelRecordTask($existingTask['id']);
                    } else {
                        return $this->createSuccessJsonResponse([
                            'message' => '下载任务进行中',
                            'task_id' => $existingTask['id'],
                            'status' => $status,
                            'stream_id' => $existingTask['stream_id'],
                        ]);
                    }
                }
                // 已完成的任务
                elseif ($status === 'done') {
                    if (!$force) {
                        // 返回已有文件信息
                        return $this->createSuccessJsonResponse([
                            'message' => '录像已存在',
                            'task_id' => $existingTask['id'],
                            'customized_path' => $existingTask['customized_path'] ?? null,
                            'record_start_time' => $existingTask['record_start_time'] ?? null,
                            'record_end_time' => $existingTask['record_end_time'] ?? null,
                        ]);
                    }
                    // force=true 时继续，删除旧任务后重新创建
                }

                // 删除旧任务（任何状态）
                $this->getRecordTaskService()->deleteRecordTask($existingTask['id']);
            }

            $result = $this->downloadRecordCore($channel['device_id'], $channel['channel_id'], $startTime, $endTime, $downloadSpeed);

            return $this->createSuccessJsonResponse([
                'message' => '录像下载已开始',
                'stream_id' => $result['stream_id'],
                'download_url' => $result['download_url'] ?? null,
                'file_size' => $result['file_size'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * @return Gb28181Service
     */
    protected function getGb28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return DeviceService
     */
    protected function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return RecordTaskService
     */
    protected function getRecordTaskService(): RecordTaskService
    {
        return $this->createService('Record:RecordTaskService');
    }



    /**
     * 处理流媒体异常
     */
    protected function handleStreamException(\Throwable $e): \support\Response
    {
        $code = $e->getCode() ?: 500;
        $message = $e->getMessage();

        // 根据异常类型返回不同的错误信息
        if ($e instanceof \InvalidArgumentException) {
            return $this->createErrorJsonResponse($message, is_numeric($code) ? (int)$code : 400);
        }

        return $this->createErrorJsonResponse($message, is_numeric($code) ? (int)$code : 500);
    }
}
