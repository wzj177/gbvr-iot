<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\MediaServer\Service\MediaServerService;
use support\Log;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\Paginator;

/**
 * GB28181 通道管理控制器 - 管理后台
 */
class GB28181ChannelController extends BaseController
{
    /**
     * 获取设备通道列表
     */
    public function index(Request $request)
    {


        // 构建查询条件
        $conditions = [];

        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        // device_id
        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }

        if ($request->get('keyword')) {
            $conditions['keyword'] = $request->get('keyword');
        }

        $total = $this->getDeviceService()->countChannels($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $channels = $this->getDeviceService()->searchChannels($conditions, ['id' => 'DESC'], $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        // 关联查询媒体服务器信息
        $mediaServerIds = array_filter(array_unique(array_column($channels, 'media_server_id')));
        $mediaServers = !empty($mediaServerIds)
            ? $this->getMediaServerService()->findServersByServerIds($mediaServerIds)
            : [];
        $mediaServerMap = ArrayToolkit::index($mediaServers, 'server_id');

        // 为每个通道附加媒体服务器信息
        foreach ($channels as &$channel) {
            if (!empty($channel['media_server_id']) && isset($mediaServerMap[$channel['media_server_id']])) {
                $channel['media_server'] = $mediaServerMap[$channel['media_server_id']];
            } else {
                $channel['media_server'] = null;
            }
        }

        return $this->createSuccessJsonResponse([
            'list' => $channels,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取通道详情
     */
    public function show(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        // 关联查询媒体服务器信息
        if (!empty($channel['media_server_id'])) {
            $mediaServer = $this->getMediaServerService()->getServer($channel['media_server_id']);
            if ($mediaServer) {
                $channel['media_server'] = $this->filterMediaServerInfo($mediaServer);
            }
            unset($channel['media_server_id']);
        }

        return $this->createSuccessJsonResponse($channel);
    }

    /**
     * 更新通道信息
     */
    public function update(Request $request, $id)
    {
        $channel = $this->getDeviceService()->getChannelById($id);

        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }

        $data = $request->post();
        $allowedFields = [
            'show_name',    // 显示名称
            'origin_code',  // 级联编号
            'custom_lat',   // 自填纬度
            'custom_lng',   // 自填经度
        ];

        // 过滤只允许更新的字段
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $this->createErrorJsonResponse('没有可更新的字段', 400);
        }

        // 验证经纬度格式（如果提供）
        if (isset($updateData['custom_lat'])) {
            $lat = (float)$updateData['custom_lat'];
            if ($lat < -90 || $lat > 90) {
                return $this->createErrorJsonResponse('纬度必须在 -90 到 90 之间', 400);
            }
        }

        if (isset($updateData['custom_lng'])) {
            $lng = (float)$updateData['custom_lng'];
            if ($lng < -180 || $lng > 180) {
                return $this->createErrorJsonResponse('经度必须在 -180 到 180 之间', 400);
            }
        }

        try {
            $this->getDeviceService()->updateChannel($id, $updateData);

            return $this->createSuccessJsonResponse(null, '更新成功');
        } catch (\Exception $e) {
            Log::error('Update channel failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('更新通道失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 批量绑定媒体服务器
     */
    public function batchBindMedia(Request $request)
    {
        $ids = $request->post('ids', []);
        $mediaServerId = $request->post('server_id', 'default');

        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要绑定的通道');
        }

        if (empty($mediaServerId)) {
            return $this->createErrorJsonResponse('请选择媒体服务器');
        }

        // 验证媒体服务器是否存在
        $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($mediaServerId);
        if (!$mediaServer) {
            return $this->createErrorJsonResponse('媒体服务器不存在');
        }

        try {
            $affectedRows = $this->getDeviceService()->batchUpdateChannels($ids, [
                'media_server_id' => $mediaServerId
            ]);

            $this->getLogService()->info('gb28181', 'batch_bind_media_channel', "批量绑定媒体服务器到通道，成功: {$affectedRows}个", [
                'ids' => $ids,
                'mediaServerId' => $mediaServerId,
            ]);

            return $this->createSuccessJsonResponse([
                'successCount' => $affectedRows,
                'message' => "成功绑定 {$affectedRows} 个通道到媒体服务器",
            ]);
        } catch (\Exception $e) {
            Log::error('Batch bind media server for channels failed', [
                'ids' => $ids,
                'mediaServerId' => $mediaServerId,
                'error' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse('批量绑定媒体服务器失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 过滤媒体服务器敏感信息，只保留必要的字段
     */
    private function filterMediaServerInfo(array $server): array
    {
        return [
            'id' => $server['id'],
            'name' => $server['name'] ?? '',
            'type' => $server['type'] ?? '',
            'host' => $server['host'] ?? '',
            'port' => $server['port'] ?? '',
        ];
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return MediaServerService
     */
    private function getMediaServerService(): MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }
}