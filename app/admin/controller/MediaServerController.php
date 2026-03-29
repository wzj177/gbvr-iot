<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use support\Request;
use support\Response;
use support\utils\Paginator;

class MediaServerController extends BaseController
{
    public function index(Request $request)
    {
        $conditions = [];
        $fields = $request->get();

        if (!empty($fields['keywords'])) {
            $conditions['keywords'] = $fields['keywords'];
        }

        if (!empty($fields['type'])) {
            $conditions['type'] = $fields['type'];
        }

        if (!empty($fields['status'])) {
            $conditions['status'] = $fields['status'];
        }

        if (isset($fields['support_gb28181'])) {
            $conditions['support_gb28181'] = (int)$fields['support_gb28181'];
        }

        $total = $this->getMediaServerService()->countMediaServers($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);
        $sort = $this->getSort($request);
        $sort['id'] = 'DESC';
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);
        $files = $this->getMediaServerService()->searchMediaServers($conditions, $sort, $paginator->getOffsetCount(), $paginator->getPerPageCount());

        return $this->createSuccessJsonResponse([
            'list' => $files,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * Restart media server
     */
    public function restart(Request $request, $id): Response
    {
        try {
            $result = $this->getMediaServerService()->restart((int)$id);

            if ($result) {
                return $this->createSuccessJsonResponse(null, '重启命令已发送');
            }

            return $this->createErrorJsonResponse('重启失败');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'restart_media_server', '重启媒体服务器失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get ZLMediaKit stats
     */
    public function getZLMediaKitStats(Request $request, $id): Response
    {
        try {
            $stats = $this->getMediaServerService()->getStats((int)$id);

            return $this->createSuccessJsonResponse($stats);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'get_media_server_stats', '获取媒体服务器统计失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Store new media server
     */
    public function store(Request $request): Response
    {
        $data = $request->post();

        $allowedFields = [
            'name',           // 服务器名称
            'type',           // 服务器类型
            'support_gb28181', // 是否支持GB28181协议
            'host',           // 服务器地址
            'port',           // 服务器端口
            'https_port',     // https端口
            'stream_ip',      // 收流IP（用于SDP，为空则使用host）
            'secret',         // 认证密钥
            'http_port',      // HTTP端口
            'https_port',     // HTTPS端口
            'hook_alive_interval', // 心跳间隔（秒）
            'rtp_port_range', // RTP端口范围
            'default_server', // 是否为默认服务器
            'status',         // 状态
            'area_id',        // 区域ID
            'remark',         // 备注
            'record_path',     // 录制路径
            'send_rtp_port_range' // 发送RTP端口范围
        ];

        // 过滤只允许创建的字段
        $createData = array_intersect_key($data, array_flip($allowedFields));

        // 验证必填字段
        $required = ['name', 'type', 'host', 'port', 'https_port'];
        foreach ($required as $field) {
            if (empty($createData[$field])) {
                return $this->createErrorJsonResponse("缺少必填字段: {$field}", 400);
            }
        }

        try {
            return $this->createSuccessJsonResponse($this->getMediaServerService()->createMediaServer($createData), '创建成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'create_media_server', '创建媒体服务器失败', [
                'data' => $createData,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Set media server config
     */
    public function setConfig(Request $request, $id): Response
    {
        $config = $request->post();

        if (empty($config)) {
            return $this->createErrorJsonResponse('配置不能为空', 400);
        }

        try {
            $result = $this->getMediaServerService()->setConfig((int)$id, $config);

            if ($result) {
                return $this->createSuccessJsonResponse(null, '配置保存成功');
            }

            return $this->createErrorJsonResponse('配置保存失败');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'set_media_server_config', '设置媒体服务器配置失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get media server config
     */
    public function getConfig(Request $request, $id): Response
    {
        try {
            $config = $this->getMediaServerService()->getConfig((int)$id);

            return $this->createSuccessJsonResponse($config);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'get_media_server_config', '获取媒体服务器配置失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Update media server
     */
    public function update(Request $request, $id): Response
    {
        $data = $request->post();

        $allowedFields = [
            'name',           // 服务器名称
            'type',           // 服务器类型
            'support_gb28181', // 是否支持GB28181协议
            'host',           // 服务器地址
            'port',           // 服务器端口
            'stream_ip',      // 收流IP（用于SDP，为空则使用host）
            'secret',         // 认证密钥
            'http_port',      // HTTP端口
            'https_port',     // HTTPS端口
            'hook_alive_interval', // 心跳间隔（秒）
            'rtp_port_range', // RTP端口范围
            'default_server', // 是否为默认服务器
            'status',         // 状态
            'area_id',        // 区域ID
            'remark',         // 备注
            'record_path'     // 录制路径
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        try {
            $this->getMediaServerService()->updateMediaServer((int)$id, $updateData);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'update_media_server', '更新媒体服务器失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Delete media server
     */
    public function delete(Request $request, $id): Response
    {
        try {
            $this->getMediaServerService()->deleteMediaServerById((int)$id);

            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'delete_media_server', '删除媒体服务器失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * Show media server details
     */
    public function show(Request $request, $id): Response
    {
        try {
            $server = $this->getMediaServerService()->getMediaServerById((int)$id);

            if (!$server) {
                return $this->createErrorJsonResponse('媒体服务器不存在', 404);
            }

            return $this->createSuccessJsonResponse($server);
        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, 'get_media_server', '获取媒体服务器失败', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * @return MediaServerService
     */
    protected function getMediaServerService(): MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }
}
