<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\MediaServer\Service\MediaServerService;
use support\Log;
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
            Log::error('Media server restart failed', [
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
            Log::error('Get media server stats failed', [
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

        // 验证必填字段
        $required = ['name', 'type', 'host', 'port'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->createErrorJsonResponse("缺少必填字段: {$field}", 400);
            }
        }

        try {
            return $this->createSuccessJsonResponse($this->getMediaServerService()->createMediaServer($data), '创建成功');
        } catch (\Exception $e) {
            Log::error('Create media server failed', [
                'data' => $data,
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
            Log::error('Set media server config failed', [
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
            Log::error('Get media server config failed', [
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

        try {
            $this->getMediaServerService()->updateMediaServer((int)$id, $data);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            Log::error('Update media server failed', [
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
            Log::error('Delete media server failed', [
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
            Log::error('Get media server failed', [
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
