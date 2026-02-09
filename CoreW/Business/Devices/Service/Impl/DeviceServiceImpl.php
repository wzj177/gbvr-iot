<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Dao\StreamSessionViewerDao;
use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\ChannelTypeEnum;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Dao\DeviceDao;
use CoreW\Business\Devices\Dao\DeviceChannelsDao;
use CoreW\Business\Devices\Dao\StreamSessionsDao;
use CoreW\Business\Devices\Dao\PresetDao;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\User\CurrentUserInterface;
use CoreW\Dao\DaoProxy;
use support\exception\NotFoundException;

class DeviceServiceImpl extends BaseService implements DeviceService
{
    // ==================== 设备基础操作 ====================

    public function getDevicesById($id)
    {
        return $this->getDeviceDao()->get($id);
    }

    public function getDeviceByDeviceId(string $deviceId)
    {
        return $this->getDeviceDao()->getByDeviceId($deviceId);
    }

    public function countDevices(array $conditions)
    {
        return $this->getDeviceDao()->count($conditions);
    }

    public function searchDevices(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getDeviceDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function findDevicesByDeviceIds(array $deviceIds): array
    {
        if (empty($deviceIds)) {
            return [];
        }

        return $this->getDeviceDao()->search(['device_ids' => $deviceIds], [], 0, count($deviceIds));
    }

    public function summaryDevices(array $conditions = []): array
    {
        $totalCount = $this->countDevices($conditions);
        $onlineCount = $this->countDevices(array_merge(['status' => DeviceStatusEnum::ONLINE->value], $conditions)); // 注册在线
        $expiredCount = $this->countDevices(array_merge(['status' => DeviceStatusEnum::EXPIRED->value], $conditions));
        $unregisterCount = $this->countDevices(array_merge(['status' => DeviceStatusEnum::UNREGISTERED->value], $conditions));

        return [
            'total_count' => $totalCount,
            'online_count' => $onlineCount,
            'expired_count' => $expiredCount,
            'unregister_count' => $unregisterCount,
        ];
    }

    public function createDevice(array $fields)
    {
        $device = array_merge([
            'status' => DeviceStatusEnum::UNREGISTERED->value,
            'enabled' => true,
            'device_name' => $fields['device_name'] ?? $fields['device_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        return $this->getDeviceDao()->create($device);
    }

    public function updateDevice($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $resp = $this->getDeviceDao()->update($id, $fields);

        return $resp;
    }

    public function updateDeviceExtendInfo($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $resp = $this->getDeviceDao()->update($id, $fields);
        if ($resp) {
            $this->syncDeviceConfig($this->getDevicesById($id));
        }

        return $resp;
    }

    public function syncDeviceConfig(array $device): bool
    {
        if ($device['status'] !== DeviceStatusEnum::ONLINE->value) {
            return false;
        }

        return $this->getGb28181Service()->updateDevice($device);
    }

    public function deleteDeviceById($id)
    {
        $this->beginTransaction();
        try {
            $this->getDeviceDao()->delete($id);
            $this->getDeviceChannelsDao()->deleteByDeviceId($id);
            $this->getStreamSessionsDao()->deleteByDeviceId($id);
            $this->commit();

            return true;
        } catch (\Exception $e) {
            $this->rollback();
            return false;
        }
    }

    // ==================== 设备注册相关 ====================

    /**
     * 处理设备注册
     */
    public function handleDeviceRegister(string $deviceId, array $data): array
    {
        $device = $this->getDeviceByDeviceId($deviceId);

        $now = date('Y-m-d H:i:s');
        $deviceData = [
            'status' => DeviceStatusEnum::ONLINE->value,
            'device_id' => $deviceId,
            'device_type' => $this->parseDeviceTypeByDeviceId($deviceId),
            'registered_at' => isset($data['registered_at']) ? date('Y-m-d H:i:s', $data['registered_at']) : $now,
//            'last_heartbeat_at' => isset($data['timestamp']) ? date('Y-m-d H:i:s', $data['timestamp']) : $now,
            'last_heartbeat_at' => $data['timestamp'] ?? time(),
        ];

        if (!empty($data['from_uri'])) {
            $deviceData['from_uri'] = $data['from_uri'];
        }

        if (!empty($data['ip'])) {
            $deviceData['ip'] = $data['ip'];
        }

        if (!empty($data['port'])) {
            $deviceData['port'] = $data['port'];
        }

        if (!empty($data['contact'])) {
            $deviceData['contact'] = $data['contact'];
        }

        if (!empty($data['user_agent'])) {
            $deviceData['user_agent'] = $data['user_agent'];
        }

        if (!empty($data['expires'])) {
            $deviceData['expires'] = (int)$data['expires'];
        }

        if ($device) {
            $resp = $this->updateDevice($device['id'], $deviceData);
            $this->updateChannelsStatusByDeviceId($deviceId, $resp['status']);
            return $resp;
        } else {
            return $this->createDevice($deviceData);
        }
    }

    public function updateDeviceHeartbeat(string $deviceId): bool
    {
        $device = $this->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return false;
        }

        return (bool)$this->updateDevice($device['id'], [
            'last_heartbeat_at' => time(),
        ]);
    }

    public function updateDeviceStatus(string $deviceId, string $status): bool
    {
        $device = $this->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return false;
        }


        if ($device['status'] === $status) {
            // 状态未改变
            return true;
        }

        if ($device['status'] === DeviceStatusEnum::UNREGISTERED->value && in_array($status, [DeviceStatusEnum::EXPIRED->value])) {
            // 已注销状态，不能修改为心跳超时 或者离线
            return true;
        }

        $this->beginTransaction();
        try {
            $this->updateDevice($device['id'], [
                'status' => $status,
                'expires' => $status === DeviceStatusEnum::UNREGISTERED->value ? 0 : $device['expires']
            ]);
            $this->updateChannelsStatusByDeviceId($deviceId, $status);
            $this->commit();

            return true;
        } catch (\Exception $e) {
            $this->rollback();

            return false;
        }
    }

    // ==================== 设备通道操作 ====================

    /**
     * 更新设备通道状态
     * @param string $deviceId
     * @param string $status
     * @return array|float|int|mixed|string|null
     * @throws \CoreW\Dao\DaoException
     */
    protected function updateChannelsStatusByDeviceId(string $deviceId, string $status)
    {
        $data = ['status' => $status];
        if ($status === DeviceStatusEnum::UNREGISTERED->value || $status === DeviceStatusEnum::EXPIRED->value) {
            $data['stream_status'] = ChannelStreamStatus::IDLE->value; // 更新推流状态
        }

        return $this->getDeviceChannelsDao()->update(['device_id' => $deviceId], $data);
    }

    public function getChannelById($id)
    {
        return $this->getDeviceChannelsDao()->get($id);
    }

    public function getChannelByDeviceAndChannel(string $deviceId, string $channelId)
    {
        return $this->getDeviceChannelsDao()->getByDeviceAndChannel($deviceId, $channelId);
    }

    public function countChannels(array $conditions)
    {
        return $this->getDeviceChannelsDao()->count($conditions);
    }

    public function searchChannels(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getDeviceChannelsDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function getChannelsByDeviceId(string $deviceId)
    {
        return $this->getDeviceChannelsDao()->findByDeviceId($deviceId);
    }

    public function createChannel(array $fields)
    {
        $channel = array_merge([
            'status' => DeviceStatusEnum::UNREGISTERED->value,
            'enabled' => false,
            'media_server_id' => 'default',
            'channel_type' => $this->parseDeviceChanelTypeByDeviceId($fields['channel_id']),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);
        // 获取到音视频通道时会自动向数据库VideoChannel表写入此通道的数据，同时确定了此通道的SSRC值和ZLMediaKit的StreamId值，数据库中这条记录的MainId则是SSRC的crc32后的16进制字符串，也同时是ZLMediaKit的StreamId
        [$ssrc, $streamId] = $this->getGb28181Service()->getSSRCInfo($channel['device_id'], $channel['channel_id']);
        $channel['ssrc'] = $ssrc;
        $channel['main_id'] = $streamId; // 保持和akstream一致，效果和stream_id一样
        $channel['stream_id'] = $streamId; // ZLM 流ID

//        if (empty($channel['ssrc'])) {
//            $channel['ssrc'] = $this->generateUniqueSsrc();
//            $channel['main_id'] = $this->ssrcIdToCrc32Hex($channel['ssrc']);
//        }
//
//        if (empty($channel['stream_id']) && !empty($channel['device_id']) && !empty($channel['channel_id'])) {
//            $channel['stream_id'] = "{$channel['device_id']}_{$channel['channel_id']}";
//        }

        return $this->getDeviceChannelsDao()->create($channel);
    }

    public function updateChannel($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getDeviceChannelsDao()->update($id, $fields);
    }

    public function updateChannelByMainId(string $mainId, array $fields)
    {
        $channel = $this->getDeviceChannelsDao()->getByMainId($mainId);
        if (!$channel) {
            return false;
        }
        return $this->updateChannel($channel['id'], $fields);
    }

    public function batchUpdateChannels(array $ids, array $fields): int
    {
        if (empty($ids)) {
            return 0;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getDeviceChannelsDao()->update(['ids' => $ids], $fields);
    }

    public function deleteChannel($id): bool
    {
        $channel = $this->getChannelById($id);
        if (!$channel) {
            return false;
        }

        $this->beginTransaction();
        try {
            // 删除通道关联的流会话
            $this->getStreamSessionsDao()->deleteByStreamId($channel['stream_id']);

            // 删除通道
            $this->getDeviceChannelsDao()->delete($id);

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * 批量创建或更新通道
     * @param string $deviceId
     * @param array $devices
     * @return int
     * @throws \CoreW\Dao\DaoException
     */
    public function batchUpdateOrCreateChannels(string $deviceId, array $devices): int
    {
        $device = $this->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return -1;
        }

        $count = 0;
        $channels = [];
        try {
            $this->beginTransaction();
            foreach ($devices as $item) {
                $channelId = $item['DeviceID'] ?? '';
                if (!$channelId) {
                    continue;
                }

                $channel = $this->getChannelByDeviceAndChannel($deviceId, $channelId);
                [$ssrc, $streamId] = $this->getGb28181Service()->getSSRCInfo($deviceId, $channelId);
                $channelData = [
                    'channel_name' => $item['Name'] ?? '',
                    'manufacturer' => $item['Manufacturer'] ?? '',
                    'model' => $item['Model'] ?? '',
                    'owner' => $item['Owner'] ?? '',
                    'civil_code' => $item['CivilCode'] ?? '',
                    'block' => $item['Block'] ?? '',
                    'address' => $item['Address'] ?? '',
                    'parental' => $item['Parental'] ?? 0,
                    'parent_id' => $item['ParentID'] ?? '',
                    'safety_way' => $item['SafetyWay'] ?? 0,
                    'register_way' => $item['RegisterWay'] ?? 1,
                    'cert_num' => $item['CertNum'] ?? '',
                    'certifiable' => $item['Certifiable'] ?? 0,
                    'ip_address' => $item['IpAddress'] ?? '',
                    'err_code' => $item['ErrCode'] ?? 0,
                    'end_time' => $item['EndTime'] ?? '',
                    'secrecy' => $item['Secrecy'] ?? 0,
                    'port' => $item['Port'] ?? 0,
                    'password' => $item['Password'] ?? '',
                    'status' => ($item['Status'] ?? 'OFF') === 'ON' ? DeviceStatusEnum::ONLINE->value : DeviceStatusEnum::UNREGISTERED->value,
                    'lng' => $item['Longitude'] ?? 0.0,
                    'lat' => $item['Latitude'] ?? 0.0,
                    'channel_type' => $this->parseDeviceChanelTypeByDeviceId($channelId),
                ];
                $channelData['ssrc'] = $ssrc;
                $channelData['main_id'] = $streamId; // 保持和akstream一致，效果和stream_id一样
                $channelData['stream_id'] = $streamId; // ZLM 流ID
                if ($channel) {
                    $this->updateChannel($channel['id'], $channelData);
                } else {
                    $channelData['device_id'] = $deviceId;
                    $channelData['channel_id'] = $channelId;
                    $channelData['created_at'] = date('Y-m-d H:i:s');
                    $channelData['updated_at'] = date('Y-m-d H:i:s');
                    $channels[] = $channelData;
                    $count++;
//                $this->createChannel($channelData);
                }

            }

            if (!empty($channels)) {
                $this->getDeviceChannelsDao()->batchCreate($channels);
            }

            if ($count > 0) {
                $this->updateDevice($device['id'], [
                    'sum_num' => $count,
                    'device_name' => empty($devices['device_name']) && $count === 1 ? $channels[0]['channel_name'] : $devices['device_name']
                ]);
            }

            $this->commit();

            return $count;
        } catch (\Exception $exception) {
            $this->rollback();
            throw $exception;
        }

    }


    /**
     * 解析设备类型
     * @param string $channelId
     * @return string
     */
    public function parseDeviceTypeByDeviceId(string $channelId): string
    {
        // 验证长度
        if (strlen($channelId) < 20 || !ctype_digit($channelId)) {
            return 'Invalid';
        }

        // 提取第11~13位（索引从0开始，即10~12）
        $typeCode = substr($channelId, 10, 3);
        $typeMap = [
            // 平台类
            '001' => '中心平台',         // 省/市/区级监控平台（标准定义）

            // 前端主设备（GB/T 28181-2016 附录 D 明确列出 [[1]][[7]]）
            '111' => 'DVR',             // 数字硬盘录像机（Digital Video Recorder）
            '112' => '视频服务器',       // Video Server（如编码/解码服务器）
            '113' => '编码器',           // Encoder
            '114' => '解码器',           // Decoder
            '115' => '视频切换矩阵',     // Video Switch Matrix
            '116' => '音频设备',         // Audio Device（部分扩展）
            '121' => 'DVR',             // 【注意】部分厂商/平台将 121 用作传统 DVR（与111并存）
            '131' => 'NVR',             // 网络视频录像机（Network Video Recorder）
            '132' => 'HCVR',            // 混合视频录像机（Hybrid DVR，支持模拟+IP）

            // 摄像头类（实践中广泛使用，但标准未明文定义“111=IPC”）
            '110' => 'IPC',             // 部分厂商使用（非标）

            // 报警类（标准定义 [[1]]）
            '211' => '报警主机',
            '212' => '报警输出设备',
            '213' => '报警输入设备',

            // 其他
            '300' => '智能分析设备',     // 行业扩展（如 AI 盒子）
            '310' => '移动设备',         // 如车载、单兵设备（扩展）
        ];

        return $typeMap[$typeCode] ?? 'Unknown';
    }

    /**
     * 解析设备通道类型
     * @param string $channelId
     * @return string
     */
    public function parseDeviceChanelTypeByDeviceId(string $channelId): string
    {
        // 验证 DeviceID 格式
        if (strlen($channelId) < 20 || !ctype_digit($channelId)) {
            $channelType = 'unknown';
        } else {
            // 提取设备类型码（第11~13位，索引10~12）
            $typeCode = substr($channelId, 10, 3);
            $channelType = ChannelTypeEnum::tryFromInt((int)$typeCode);
            if (!$channelType) {
                $channelType = 'unknown';
            } else {
                $channelType = $channelType->value;
            }
        }

        return $channelType;
    }

    // ==================== 流会话操作 ====================

    public function getSessionById($id): ?array
    {
        return $this->getStreamSessionsDao()->get($id);
    }

    public function getSessionByCallId(int $callId)
    {
        return $this->getStreamSessionsDao()->getByCallId($callId);
    }

    public function getSessionByStreamId(string $streamId)
    {
        return $this->getStreamSessionsDao()->getByStreamId($streamId);
    }

    public function getActiveSessionByStreamIdAndType(string $streamId, string $type)
    {
        return $this->getStreamSessionsDao()->getActiveByStreamIdAndType($streamId, $type);
    }

    /**
     * 增加观看者数（console、queue不能用）
     *
     * @param string $streamId
     * @return int|void
     */
    public function incrementSessionViewerCount(string $streamId)
    {
        $stream = $this->getSessionByStreamId($streamId);
        if (!$stream) {
            return 0;
        }

        try {
            $result = $this->getStreamSessionsDao()->increment($stream['id'], 'viewer_count', 1);
            if ($result) {
                $currentAuthUser = $this->bfw->offsetGet('user');
                $viewerId = '';
                if ($currentAuthUser instanceof CurrentUserInterface) {
                    $viewerId = $currentAuthUser->getId();
                }

                $viewerIp = \request()->getRealIp();
                $this->getStreamSessionViewerDao()->create([
                    'stream_id' => $streamId,
                    'viewer_id' => $viewerId,
                    'viewer_ip' => $viewerIp,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            echo $e->getTraceAsString();
            return 0;
        }
    }

    /**
     * 减少观看者数（console、queue不能用）
     *
     * @param string $streamId
     * @return int|void
     */
    public function decrementSessionViewerCount(string $streamId)
    {
        $stream = $this->getSessionByStreamId($streamId);
        if (!$stream || $stream['viewer_count'] == 0) {
            return 0;
        }

        return $this->getStreamSessionsDao()->decrement($stream['id'], 'viewer_count', 1);
    }

    public function getSessionBySsrc(string $ssrc): array
    {
        return $this->getStreamSessionsDao()->getBySsrc($ssrc);
    }


    public function countSessions(array $conditions): int
    {
        return $this->getStreamSessionsDao()->count($conditions);
    }

    public function searchSessions(array $conditions, array $orderBys, $start, $limit, $columns = []): array
    {
        return $this->getStreamSessionsDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function batchDeleteSessions(array $ids)
    {
        if (empty($ids)) {
            return false;
        }

        return $this->getStreamSessionsDao()->batchDelete(['ids' => $ids]);
    }

    public function createSession(array $fields)
    {
        $session = array_merge([
            'status' => 'inviting',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        return $this->getStreamSessionsDao()->create($session);
    }

    public function updateSession($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getStreamSessionsDao()->update($id, $fields);
    }

    public function updateSessionByCallId(int $callId, array $fields): bool
    {
        $session = $this->getSessionByCallId($callId);
        if (!$session) {
            return false;
        }

        return (bool)$this->updateSession($session['id'], $fields);
    }

    public function updateSessionBySSRC(string $ssrc, array $fields)
    {
        $session = $this->getSessionBySsrc($ssrc);
        if (!$session) {
            return null;
        }

        return $this->updateSession($session['id'], $fields);
    }

    public function deleteSession($id)
    {
        return $this->getStreamSessionsDao()->delete($id);
    }

    public function deleteSessionByCallId(int $callId): bool
    {
        $session = $this->getSessionByCallId($callId);
        if (!$session) {
            return false;
        }

        return (bool)$this->deleteSession($session['id']);
    }

    public function deleteSessionByStreamIdAndMediaServerId(string $streamId, string $mediaServerId)
    {
        return $this->getStreamSessionsDao()->batchDelete([
            'stream_id' => $streamId,
            'media_server_id' => $mediaServerId
        ]);
    }

    public function cleanupExpiredSessions(int $ttl = 300): int
    {
        $expireTime = date('Y-m-d H:i:s', time() - $ttl);

        return $this->getStreamSessionsDao()->deleteAllByExpireTime($expireTime);

//        return Db::table('gv_stream_sessions')
//            ->where('updated_at', '<', $expireTime)
//            ->whereIn('status', ['inviting', 'ringing'])
//            ->delete();
    }
    
    /**
     * 获取冷却中的端口
     * 
     * @param int $coolingTime 冷却时间（秒），默认20秒
     * @return array 端口列表
     */
    public function getCoolingPorts(int $coolingTime = 20): array
    {
        return $this->getStreamSessionsDao()->getCoolingPorts($coolingTime);
    }

    // ==================== 树形数据 ====================

    /**
     * 获取设备树形数据
     * @param string $treeType 树类型: dc=设备-通道树, area=行政区域-设备-通道树
     * @return array
     */
    public function getDeviceTree(string $treeType = 'dc'): array
    {
        if ($treeType === 'area') {
            return $this->getAreaDeviceTree();
        }

        return $this->getDeviceChannelTree();
    }

    /**
     * 获取推送设备列表（包含订阅配置）
     * 用于 Gateway 启动时恢复设备状态
     *
     * @param array $deviceIds 设备ID列表
     * @return array 设备列表，每个设备包含 subscription_status 字段
     */
    public function getDevicesForPush(array $deviceIds): array
    {
        return $this->getDeviceDao()->getDevicesForPush($deviceIds);
    }

    /**
     * 获取设备-通道树
     * @return array
     */
    private function getDeviceChannelTree(): array
    {
        $devices = $this->searchDevices([], [
            'status' => 'ASC',
            'id' => 'DESC'
        ], 0, PHP_INT_MAX);
        $tree = [];

        foreach ($devices as $device) {
            $node = [
                'id' => 'd_' . $device['id'],
                'key' => $device['device_id'],
                'label' => $device['show_name'] ?: $device['device_name'] ?: $device['device_id'],
                'type' => 'device',
                'device_id' => $device['device_id'],
                'device_type' => $device['device_type'],
                'status' => $device['status'],
                'enabled' => $device['enabled'],
                'ip' => $device['ip'],
                'port' => $device['port'],
                'manufacturer' => $device['manufacturer'],
                'model' => $device['model'],
                'province_id' => $device['province_id'],
                'city_id' => $device['city_id'],
                'county_id' => $device['county_id'],
                'children' => [],
            ];

            $channels = $this->getChannelsByDeviceId($device['device_id']);
            foreach ($channels as $channel) {
                // 只展示摄像机(131)和网络摄像机(132)
                if (!in_array($channel['channel_type'], [131, 132])) {
                    continue;
                }
                $node['children'][] = [
                    'id' => 'c_' . $channel['id'],
                    'key' => $channel['channel_id'],
                    'label' => $channel['show_name'] ?: $channel['channel_name'] ?: $channel['channel_id'],
                    'type' => 'channel',
                    'device_id' => $channel['device_id'],
                    'channel_id' => $channel['channel_id'],
                    'channel_type' => $channel['channel_type'],
                    'status' => $channel['status'],
                    'enabled' => $channel['enabled'],
                    'parental' => $channel['parental'],
                    'parent_id' => $channel['parent_id'],
                    'civil_code' => $channel['civil_code'],
                    'stream_status' => $channel['stream_status'],
                ];
            }

            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * 获取行政区域-设备-通道树
     * @return array
     */
    private function getAreaDeviceTree(): array
    {
        $devices = $this->searchDevices([], ['id' => 'ASC'], 0, 10000);
        $areaMap = [];

        foreach ($devices as $device) {
            $provinceId = $device['province_id'] ?: '000000';
            $cityId = $device['city_id'] ?: '000000';
            $countyId = $device['county_id'] ?: '000000';

            // 构建区域路径
            $areaKey = $provinceId . '-' . $cityId . '-' . $countyId;

            if (!isset($areaMap[$areaKey])) {
                $areaMap[$areaKey] = [
                    'id' => 'a_' . $areaKey,
                    'key' => $areaKey,
                    'label' => $this->getAreaName($provinceId, $cityId, $countyId),
                    'type' => 'area',
                    'province_id' => $provinceId,
                    'city_id' => $cityId,
                    'county_id' => $countyId,
                    'children' => [],
                ];
            }

            $deviceNode = [
                'id' => 'd_' . $device['id'],
                'key' => $device['device_id'],
                'label' => $device['show_name'] ?: $device['device_name'] ?: $device['device_id'],
                'type' => 'device',
                'device_id' => $device['device_id'],
                'device_type' => $device['device_type'],
                'status' => $device['status'],
                'enabled' => $device['enabled'],
                'ip' => $device['ip'],
                'port' => $device['port'],
                'manufacturer' => $device['manufacturer'],
                'model' => $device['model'],
                'children' => [],
            ];

            $channels = $this->getChannelsByDeviceId($device['device_id']);
            foreach ($channels as $channel) {
                // 只展示摄像机(131)和网络摄像机(132)
                if (!in_array($channel['channel_type'], [131, 132])) {
                    continue;
                }
                $deviceNode['children'][] = [
                    'id' => 'c_' . $channel['id'],
                    'key' => $channel['channel_id'],
                    'label' => $channel['show_name'] ?: $channel['channel_name'] ?: $channel['channel_id'],
                    'type' => 'channel',
                    'device_id' => $channel['device_id'],
                    'channel_id' => $channel['channel_id'],
                    'channel_type' => $channel['channel_type'],
                    'status' => $channel['status'],
                    'enabled' => $channel['enabled'],
                    'parental' => $channel['parental'],
                    'parent_id' => $channel['parent_id'],
                    'civil_code' => $channel['civil_code'],
                    'stream_status' => $channel['stream_status'],
                ];
            }

            $areaMap[$areaKey]['children'][] = $deviceNode;
        }

        return array_values($areaMap);
    }

    /**
     * 获取区域名称
     * @param string $provinceId
     * @param string $cityId
     * @param string $countyId
     * @return string
     */
    private function getAreaName(string $provinceId, string $cityId, string $countyId): string
    {
        // 这里可以调用区域服务获取真实名称，暂时返回代码组合
        $parts = [];
        if ($provinceId && $provinceId !== '000000') {
            $parts[] = $provinceId;
        }
        if ($cityId && $cityId !== '000000') {
            $parts[] = $cityId;
        }
        if ($countyId && $countyId !== '000000') {
            $parts[] = $countyId;
        }

        return empty($parts) ? '未知区域' : implode('-', $parts);
    }

    // ==================== SSRC 管理 ====================

    public function generateUniqueSsrc(): string
    {
        $maxAttempts = 100;
        $attempt = 0;

        do {
            $ssrc = str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
            $exists = $this->getDeviceChannelsDao()->existBySsrc($ssrc);
            if (!$exists) {
                return $ssrc;
            }

            $attempt++;
        } while ($attempt < $maxAttempts);

        return substr((string)(time() * 1000 + rand(0, 999)), -10);
    }

    // ==================== 预置位管理 ====================

    public function getPresetList(string $deviceId, string $channelId): array
    {
        return $this->getPresetDao()->findByDeviceAndChannel($deviceId, $channelId);
    }

    public function setPreset(string $deviceId, string $channelId, int $value, string $name = ''): array
    {
        // 验证预置位编号范围
        if ($value < 1 || $value > 255) {
            throw new \InvalidArgumentException('预置位编号必须在1-255之间');
        }

        // 检查设备和通道是否存在
        $channel = $this->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            throw new NotFoundException('设备和通道不存在');
        }

        // 检查是否已存在该预置位
        $existing = $this->getPresetDao()->getByDeviceAndChannelAndValue($deviceId, $channelId, $value);

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            // 更新现有预置位
            $this->getPresetDao()->update($existing['id'], [
                'name' => $name ?: "预置位{$value}",
                'updated_at' => $now,
            ]);
        } else {
            // 创建新预置位
            $this->getPresetDao()->create([
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'value' => $value,
                'name' => $name ?: "预置位{$value}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->getPresetDao()->getByDeviceAndChannelAndValue($deviceId, $channelId, $value);
    }

    public function callPreset(string $deviceId, string $channelId, int $value): bool
    {
        // 验证预置位编号范围
        if ($value < 1 || $value > 255) {
            throw new \InvalidArgumentException('预置位编号必须在1-255之间');
        }

        // 检查预置位是否存在
        $preset = $this->getPresetDao()->getByDeviceAndChannelAndValue($deviceId, $channelId, $value);
        if (!$preset) {
            throw new NotFoundException('预置位不存在');
        }

        // 发送调用命令到网关
        return $this->getGb28181Service()->presetCall($deviceId, $channelId, $value);
    }

    public function deletePreset(string $deviceId, string $channelId, int $value): bool
    {
        // 验证预置位编号范围
        if ($value < 1 || $value > 255) {
            throw new \InvalidArgumentException('预置位编号必须在1-255之间');
        }

        // 检查预置位是否存在
        $preset = $this->getPresetDao()->getByDeviceAndChannelAndValue($deviceId, $channelId, $value);
        if (!$preset) {
            throw new NotFoundException('预置位不存在');
        }

        // 发送删除命令到网关
        $result = $this->getGb28181Service()->presetDelete($deviceId, $channelId, $value);

        if ($result) {
            // 删除数据库记录
            $this->getPresetDao()->delete($preset['id']);
        }

        return $result;
    }

    // ==================== DAO 获取器 ====================

    protected function getDeviceDao(): DeviceDao|DaoProxy
    {
        return $this->createDao('Devices:DeviceDao');
    }

    protected function getDeviceChannelsDao(): DeviceChannelsDao|DaoProxy
    {
        return $this->createDao('Devices:DeviceChannelsDao');
    }

    protected function getStreamSessionsDao(): StreamSessionsDao|DaoProxy
    {
        return $this->createDao('Devices:StreamSessionsDao');
    }

    protected function getStreamSessionViewerDao(): StreamSessionViewerDao|DaoProxy
    {
        return $this->createDao('Devices:StreamSessionViewerDao');
    }

    protected function getPresetDao(): PresetDao|DaoProxy
    {
        return $this->createDao('Devices:PresetDao');
    }

    protected function getGb28181Service(): Gb28181Service
    {
        return $this->bfw->offsetGet('gb28181_service');
    }
}