<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Service\RecordPlanService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use CoreW\Business\SystemLog\LogEnum;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 云端录像文件控制器 - 管理后台
 */
class GB28181RecordingController extends BaseController
{
    /**
     * 云端录像文件列表
     * GET /api/admin/gb28181/recordings
     */
    public function index(Request $request)
    {
        $conditions = [];

        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }
        if ($request->get('channel_id')) {
            $conditions['channel_id'] = $request->get('channel_id');
        }
        if ($request->get('source_type')) {
            $conditions['source_type'] = $request->get('source_type');
        }
        if ($request->get('plan_id')) {
            $conditions['plan_id'] = (int)$request->get('plan_id');
        }
        if ($request->get('stream_id')) {
            $conditions['stream_id'] = $request->get('stream_id');
        }
        if ($request->get('media_server_id')) {
            $conditions['media_server_id'] = $request->get('media_server_id');
        }

        //start_time
        if ($request->get('start_time')) {
            $conditions['start_time'] = strtotime($request->get('start_time'));
        }

        if ($request->get('end_time')) {
            $conditions['end_time'] = strtotime($request->get('end_time'));
        }

        $total = $this->getRecordFileService()->countRecordFiles($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);
        if ($request->get('no_paging', 0) === 1) {
            $offset = 0;
            $limit = PHP_INT_MAX;
        }

        // 使用带设备信息的查询方法
        $files = $this->getRecordFileService()->searchRecordFilesWithDeviceInfo($conditions, ['id' => 'DESC'], $offset, $limit);

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $files,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 录像文件详情
     * GET /api/admin/open-api/recordings/{id}
     */
    public function show(Request $request, $id) : \support\Response
    {
        $files = $this->getRecordFileService()->searchRecordFilesWithDeviceInfo(['id' => (int)$id], [], 0, 1);

        if (empty($files)) {
            return $this->createErrorJsonResponse('录像文件不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($files[0]);
    }

    /**
     * 开始录像
     * POST /api/admin/open-api/recordings/start-record
     */
    public function startRecord(Request $request) : \support\Response
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $type = (int)($request->post('type', 1)); // 0为hls，1为mp4，默认mp4
        $customizedPath = $request->post('customized_path', ''); // 自定义录像保存路径
        $maxSecond = (int)($request->post('max_second', 0)); // mp4切片时间(秒)，0=使用配置

        if (empty($deviceId) || empty($channelId)) {
            return $this->createErrorJsonResponse('device_id 和 channel_id 参数必须提供');
        }

        // 获取通道信息
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

        if (empty($channel)) {
            return $this->createErrorJsonResponse('通道不存在', null, -1, 404);
        }

        // 检查是否有 stream_id
        if (empty($channel['stream_id'])) {
            return $this->createErrorJsonResponse('通道未配置 stream_id，无法录像');
        }

        // 检查媒体服务器
        if (empty($channel['media_server_id']) || $channel['media_server_id'] === 'none') {
            return $this->createErrorJsonResponse('通道未配置媒体服务器');
        }

        // 检查是否开启自动直播
        if (empty($channel['auto_live']) || $channel['auto_live'] != 1) {
            return $this->createErrorJsonResponse('通道未开启自动直播(auto_live)，云端录像需要此功能');
        }

        // 检查是否绑定了正在使用的录像计划
        if (!empty($channel['record_plan_id']) && $channel['record_plan_id'] > 0) {
            $plan = $this->getRecordPlanService()->getPlan((int)$channel['record_plan_id']);
            if ($plan && $plan['status'] == 1) {
                return $this->createErrorJsonResponse('通道已绑定到启用的录像计划，请先解绑录像计划或关闭计划后再进行手动录像');
            }
        }

        try {
            // 调用 ZLM startRecord
            $zlmClient = $this->getGb28181Service()->getZlmClientByServerId($channel['media_server_id']);
            $result = $zlmClient->startRecord(
                '__defaultVhost__',
                'rtp',
                $channel['stream_id'],
                $type,
                $customizedPath,
                $maxSecond
            );

            if ($result) {
                // 更新通道录像状态
                $this->getDeviceService()->updateChannel($channel['id'], ['record_status' => 1]);

                $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_START_RECORDING, '手动开始录像', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                    'stream_id'  => $channel['stream_id'],
                    'type'       => $type,
                    'customized_path' => $customizedPath ?: 'default',
                    'max_second' => $maxSecond ?: 'default',
                ]);

                return $this->createSuccessJsonResponse([
                    'device_id'     => $deviceId,
                    'channel_id'    => $channelId,
                    'stream_id'     => $channel['stream_id'],
                    'type'          => $type,
                    'customized_path' => $customizedPath ?: null,
                    'max_second'    => $maxSecond ?: null,
                    'record_status' => 1,
                ], '录像已启动');
            } else {
                return $this->createErrorJsonResponse('启动录像失败，ZLM 调用失败');
            }
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_START_RECORDING, '手动开始录像异常', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'error'      => $e->getMessage(),
            ]);
            return $this->createErrorJsonResponse('启动录像失败: ' . $e->getMessage());
        }
    }

    /**
     * 停止录像
     * POST /api/admin/open-api/recordings/stop-record
     */
    public function stopRecord(Request $request) : \support\Response
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $type = (int)($request->post('type', 1)); // 0为hls，1为mp4，默认mp4

        if (empty($deviceId) || empty($channelId)) {
            return $this->createErrorJsonResponse('device_id 和 channel_id 参数必须提供');
        }

        // 获取通道信息
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

        if (empty($channel)) {
            return $this->createErrorJsonResponse('通道不存在', null, -1, 404);
        }

        // 检查是否有 stream_id
        if (empty($channel['stream_id'])) {
            return $this->createErrorJsonResponse('通道未配置 stream_id，无法录像');
        }

        // 检查媒体服务器
        if (empty($channel['media_server_id']) || $channel['media_server_id'] === 'none') {
            return $this->createErrorJsonResponse('通道未配置媒体服务器');
        }

        try {
            // 调用 ZLM stopRecord
            $zlmClient = $this->getGb28181Service()->getZlmClientByServerId($channel['media_server_id']);
            $zlmClient->stopRecord('__defaultVhost__', 'rtp', $channel['stream_id'], $type);

            // 更新通道录像状态
            $this->getDeviceService()->updateChannel($channel['id'], ['record_status' => 0]);

            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_STOP_RECORDING, '手动停止录像', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'stream_id'  => $channel['stream_id'],
                'type'       => $type,
            ]);

            return $this->createSuccessJsonResponse([
                'device_id'     => $deviceId,
                'channel_id'    => $channelId,
                'stream_id'     => $channel['stream_id'],
                'type'          => $type,
                'record_status' => 0,
            ], '录像已停止');
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_STOP_RECORDING, '手动停止录像异常', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'error'      => $e->getMessage(),
            ]);
            return $this->createErrorJsonResponse('停止录像失败: ' . $e->getMessage());
        }
    }

    /**
     * @return RecordFileService
     */
    private function getRecordFileService() : RecordFileService
    {
        return $this->createService('RecordFile:RecordFileService');
    }

    /**
     * @return MediaServerService
     */
    private function getMediaServerService() : MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService() : DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return Gb28181Service
     */
    private function getGb28181Service() : Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return RecordPlanService
     */
    private function getRecordPlanService() : RecordPlanService
    {
        return $this->createService('Record:RecordPlanService');
    }
}
