<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\RecordFile\Service\RecordFileService;
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

        $total = $this->getRecordFileService()->countRecordFiles($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        // 使用带设备信息的查询方法
        $files = $this->getRecordFileService()->searchRecordFilesWithDeviceInfo($conditions, ['id' => 'DESC'], $offset, $limit);

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $this->formatFiles($files),
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 录像文件详情
     * GET /api/admin/gb28181/recordings/{id}
     */
    public function show(Request $request, $id)
    {
        $files = $this->getRecordFileService()->searchRecordFilesWithDeviceInfo(['id' => (int)$id], [], 0, 1);

        if (empty($files)) {
            return $this->createErrorJsonResponse('录像文件不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($this->formatFiles($files)[0]);
    }

    /**
     * 格式化录像文件数据
     */
    private function formatFiles(array $files) : array
    {
        return array_map(function ($file) {
            $file['start_time_formatted'] = $file['start_time'] ? date('Y-m-d H:i:s', $file['start_time']) : null;
            $file['end_time_formatted'] = $file['end_time'] ? date('Y-m-d H:i:s', $file['end_time']) : null;
            $file['duration_formatted'] = $file['duration'] ? gmdate('H:i:s', $file['duration']) : null;
            $file['file_size_mb'] = $file['file_size'] ? round($file['file_size'] / 1048576, 2) : 0;

            // 使用实时查询的通道名称，如果没有则使用存储的历史名称
            $file['channel_name_display'] = $file['channel_name_latest'] ?? $file['channel_name'] ?? '';
            $file['device_name'] = $file['device_name'] ?? '';

            return $file;
        }, $files);
    }

    /**
     * @return RecordFileService
     */
    private function getRecordFileService() : RecordFileService
    {
        return $this->createService('RecordFile:RecordFileService');
    }
}
