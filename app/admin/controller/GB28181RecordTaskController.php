<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 录像任务控制器 - 管理后台
 */
class GB28181RecordTaskController extends BaseController
{
    /**
     * 获取录像任务列表
     * GET /admin/api/gb28181/record-tasks
     */
    public function index(Request $request)
    {
        $conditions = [];

        // 任务类型筛选
        if ($request->get('task_type')) {
            $conditions['task_type'] = $request->get('task_type');
        }

        // 设备ID筛选
        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }

        // 通道ID筛选
        if ($request->get('channel_id')) {
            $conditions['channel_id'] = $request->get('channel_id');
        }

        // 状态筛选
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        // 媒体服务器筛选
        if ($request->get('media_server_id')) {
            $conditions['media_server_id'] = $request->get('media_server_id');
        }

        // 开始时间范围筛选
        if ($request->get('start_time_gte')) {
            $conditions['start_time_gte'] = (int)$request->get('start_time_gte');
        }
        if ($request->get('start_time_lte')) {
            $conditions['start_time_lte'] = (int)$request->get('start_time_lte');
        }

        // 结束时间范围筛选
        if ($request->get('end_time_gte')) {
            $conditions['end_time_gte'] = (int)$request->get('end_time_gte');
        }
        if ($request->get('end_time_lte')) {
            $conditions['end_time_lte'] = (int)$request->get('end_time_lte');
        }

        $total = $this->getRecordTaskService()->countRecordTasks($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        $orderBy = ['created_at' => 'DESC'];
        if ($request->get('order_by')) {
            $orderBy = [$request->get('order_by') => $request->get('order_direction', 'DESC')];
        }

        $tasks = $this->getRecordTaskService()->searchRecordTasks($conditions, $orderBy, $offset, $limit);

        // 为 status=done 的任务关联 record_file
        $tasks = $this->attachRecordFiles($tasks);

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $this->formatTasks($tasks),
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 删除录像任务
     * DELETE /admin/api/gb28181/record-tasks/:id
     */
    public function destroy(Request $request, $id)
    {
        $taskId = (int)$id;

        $task = $this->getRecordTaskService()->searchRecordTasks(['id' => $taskId], [], 0, 1);
        if (empty($task)) {
            return $this->createErrorJsonResponse('录像任务不存在', null, 404);
        }

        $task = $task[0];

        // 检查任务状态，如果是正在进行的任务，使用 cancelRecordTask
        if (in_array($task['status'], ['pending', 'inviting', 'wait_stream', 'recording', 'finalizing'])) {
            $result = $this->getRecordTaskService()->cancelRecordTask($taskId);
        } else {
            // 对于已完成、失败、取消的任务，直接删除
            $result = $this->getRecordTaskService()->deleteRecordTask($taskId);
        }

        if ($result) {
            return $this->createSuccessJsonResponse(null, '删除成功');
        }

        return $this->createErrorJsonResponse('删除失败');
    }

    /**
     * 为 status=done 的任务附加 record_file 信息
     */
    private function attachRecordFiles(array $tasks) : array
    {
        $doneTaskIds = [];
        foreach ($tasks as $task) {
            if ($task['status'] === 'done' && !empty($task['stream_id'])) {
                $doneTaskIds[] = $task['id'];
            }
        }

        if (empty($doneTaskIds)) {
            return $tasks;
        }

        // 查询所有 done 任务对应的 record_file
        $recordFiles = $this->getRecordFileService()->searchRecordFiles(
            ['source_id_IN' => $doneTaskIds, 'source_type' => 'playback_download'],
            [],
            0,
            count($doneTaskIds)
        );

        // 建立 source_id => record_file 的映射
        $fileMap = [];
        foreach ($recordFiles as $file) {
            $fileMap[$file['source_id']] = $file;
        }

        // 附加到任务中
        foreach ($tasks as &$task) {
            if ($task['status'] === 'done' && isset($fileMap[$task['id']])) {
                $task['record_file'] = $fileMap[$task['id']];
            }
        }

        return $tasks;
    }

    /**
     * 格式化任务数据
     */
    private function formatTasks(array $tasks) : array
    {
        return array_map(function ($task) {
            return [
                'id'                          => $task['id'],
                'partner_id'                  => $task['partner_id'] ?? 0,
                'task_type'                   => $task['task_type'],
                'device_id'                   => $task['device_id'],
                'channel_id'                  => $task['channel_id'],
                'media_server_id'             => $task['media_server_id'],
                'vhost'                       => $task['vhost'],
                'app'                         => $task['app'],
                'stream_id'                   => $task['stream_id'],
                'ssrc'                        => $task['ssrc'] ?? '',
                'dialog_id'                   => $task['dialog_id'] ?? null,
                'start_time'                  => $task['start_time'],
                'start_time_formatted'        => date('Y-m-d H:i:s', $task['start_time']),
                'end_time'                    => $task['end_time'],
                'end_time_formatted'          => date('Y-m-d H:i:s', $task['end_time']),
                'customized_path'             => $task['customized_path'],
                'download_speed'              => $task['download_speed'] ?? 1.0,
                'status'                      => $task['status'],
                'fail_reason'                 => $task['fail_reason'],
                'record_start_time'           => $task['record_start_time'],
                'record_start_time_formatted' => $task['record_start_time'] ? date('Y-m-d H:i:s', $task['record_start_time']) : null,
                'record_end_time'             => $task['record_end_time'],
                'record_end_time_formatted'   => $task['record_end_time'] ? date('Y-m-d H:i:s', $task['record_end_time']) : null,
                'record_duration'             => $task['record_duration'],
                'created_at'                  => $task['created_at'],
                'updated_at'                  => $task['updated_at'],
                'record_file'                 => $task['record_file'] ?? null,
            ];
        }, $tasks);
    }

    /**
     * @return RecordTaskService
     */
    private function getRecordTaskService() : RecordTaskService
    {
        return $this->createService('Record:RecordTaskService');
    }

    /**
     * @return RecordFileService
     */
    private function getRecordFileService() : RecordFileService
    {
        return $this->createService('RecordFile:RecordFileService');
    }
}
