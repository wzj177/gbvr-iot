<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DevicePositionService;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 设备位置控制器 - 管理后台
 */
class GB28181DevicePositionController extends BaseController
{
    /**
     * 设备位置历史列表
     * GET /api/admin/gb28181/device-positions
     */
    public function index(Request $request)
    {
        $conditions = [];

        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }
        if ($request->get('device_ids')) {
            // 支持批量查询，逗号分隔
            $deviceIds = explode(',', $request->get('device_ids'));
            $conditions['device_ids'] = array_filter($deviceIds);
        }
        if ($request->get('time_start')) {
            $conditions['time_GTE'] = strtotime($request->get('time_start'));
        }
        if ($request->get('time_end')) {
            $conditions['time_LTE'] = strtotime($request->get('time_end'));
        }

        $total = $this->getDevicePositionService()->countPositions($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $positions = $this->getDevicePositionService()->searchPositions($conditions, ['time' => 'DESC'], $offset, $limit);

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $this->formatPositions($positions),
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 获取设备最新位置
     * GET /api/admin/gb28181/device-positions/latest/{deviceId}
     */
    public function latest(Request $request, $deviceId)
    {
        $position = $this->getDevicePositionService()->getLatestPosition($deviceId);

        if (!$position) {
            return $this->createErrorJsonResponse('设备无位置信息', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($this->formatPosition($position));
    }

    /**
     * 获取设备轨迹
     * GET /api/admin/gb28181/device-positions/track/{deviceId}
     * Query参数: start_time, end_time
     */
    public function track(Request $request, $deviceId)
    {
        $startTime = $request->get('start_time');
        $endTime = $request->get('end_time');

        if (!$startTime || !$endTime) {
            return $this->createErrorJsonResponse('请提供start_time和end_time参数');
        }

        $track = $this->getDevicePositionService()->getDeviceTrack($deviceId, $startTime, $endTime);

        return $this->createSuccessJsonResponse([
            'device_id' => $deviceId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'count' => count($track),
            'track' => $this->formatPositions($track),
        ]);
    }

    /**
     * 电子地图 - 多设备点位（最新位置）
     * GET /api/admin/gb28181/device-positions/map/points
     * Query参数: device_ids (可选，逗号分隔的设备ID)
     *
     * 说明：查询设备表的位置（针对移动设备），优先使用custom_lat/custom_lng（用户手填），否则使用lat/lng（设备同步）
     */
    public function mapPoints(Request $request)
    {
        $deviceIds = [];
        if ($request->get('device_ids')) {
            $deviceIds = explode(',', $request->get('device_ids'));
            $deviceIds = array_filter(array_map('trim', $deviceIds));
        }

        // 查询设备表，获取带位置信息的移动设备
        $conditions = [];
        if (!empty($deviceIds)) {
            $conditions['device_ids'] = $deviceIds;
        }

        // 查询所有设备
        $devices = $this->getDeviceService()->searchDevices($conditions, [], 0, PHP_INT_MAX);

        // 过滤出有位置信息的设备，并格式化
        $result = [];
        foreach ($devices as $device) {
            // 优先使用custom位置（用户手填），否则使用设备同步的位置
            $longitude = !empty($device['custom_lng']) ? (float)$device['custom_lng'] : (float)($device['lng'] ?? 0);
            $latitude = !empty($device['custom_lat']) ? (float)$device['custom_lat'] : (float)($device['lat'] ?? 0);

            // 只返回有位置信息的设备
            if ($longitude != 0 || $latitude != 0) {
                $result[] = [
                    'device_id' => $device['device_id'],
                    'device_name' => $device['device_name'] ?? '',
                    'device_type' => $device['device_type'] ?? '',
                    'longitude' => $longitude,
                    'latitude' => $latitude,
                    'is_custom' => !empty($device['custom_lng']) && !empty($device['custom_lat']),
                ];
            }
        }

        return $this->createSuccessJsonResponse([
            'count' => count($result),
            'points' => $result,
        ]);
    }

    /**
     * 电子地图 - 多设备轨迹
     * GET /api/admin/gb28181/device-positions/map/tracks
     * Query参数: device_ids (必填，逗号分隔), start_time, end_time
     *
     * 说明：查询位置历史表，返回设备在指定时间范围内的移动轨迹
     */
    public function mapTracks(Request $request)
    {
        $deviceIdsParam = $request->get('device_ids');
        $startTime = $request->get('start_time');
        $endTime = $request->get('end_time');

        if (!$deviceIdsParam) {
            return $this->createErrorJsonResponse('请提供device_ids参数（逗号分隔）');
        }
        if (!$startTime || !$endTime) {
            return $this->createErrorJsonResponse('请提供start_time和end_time参数');
        }

        $deviceIds = explode(',', $deviceIdsParam);
        $deviceIds = array_filter(array_map('trim', $deviceIds));

        if (empty($deviceIds)) {
            return $this->createErrorJsonResponse('device_ids不能为空');
        }

        $tracks = $this->getDevicePositionService()->getMultiDeviceTracks($deviceIds, $startTime, $endTime);

        // 转换为前端友好的格式
        $result = [];
        foreach ($deviceIds as $deviceId) {
            $result[] = [
                'device_id' => $deviceId,
                'count' => isset($tracks[$deviceId]) ? count($tracks[$deviceId]) : 0,
                'track' => isset($tracks[$deviceId]) ? $this->formatPositions($tracks[$deviceId]) : [],
            ];
        }

        return $this->createSuccessJsonResponse([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'device_count' => count($result),
            'tracks' => $result,
        ]);
    }

    /**
     * 格式化位置数据列表
     */
    private function formatPositions(array $positions): array
    {
        return array_map(fn($p) => $this->formatPosition($p), $positions);
    }

    /**
     * 格式化单个位置数据
     */
    private function formatPosition(array $position): array
    {
        return [
            'id' => $position['id'],
            'device_id' => $position['device_id'],
            'longitude' => (float)$position['longitude'],
            'latitude' => (float)$position['latitude'],
            'speed' => (float)$position['speed'],
            'direction' => (int)$position['direction'],
            'altitude' => (float)$position['altitude'],
            'time' => $position['time'],
            'time_formatted' => date('Y-m-d H:i:s', $position['time']),
            'recv_time' => $position['recv_time'],
            'recv_time_formatted' => date('Y-m-d H:i:s', $position['recv_time']),
        ];
    }

    /**
     * @return DevicePositionService
     */
    private function getDevicePositionService(): DevicePositionService
    {
        return $this->createService('Devices:DevicePositionService');
    }

    /**
     * @return \CoreW\Business\Devices\Service\DeviceService
     */
    private function getDeviceService(): \CoreW\Business\Devices\Service\DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}
