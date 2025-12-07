<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Dao\DeviceDao;
use CoreW\Business\Devices\Dao\DeviceChannelsDao;
use CoreW\Business\Devices\Dao\StreamSessionsDao;
use CoreW\Dao\DaoProxy;

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

    public function createDevice(array $fields)
    {
        $device = array_merge([
            'status' => 'offline',
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
        return $this->getDeviceDao()->update($id, $fields);
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
            'status' => 'online',
            'device_id' => $deviceId,
            'device_type' => $this->parseDeviceTypeByDeviceId($deviceId),
            'registered_at' => isset($data['registered_at']) ? date('Y-m-d H:i:s', $data['registered_at']) : $now,
            'last_heartbeat_at' => isset($data['timestamp']) ? date('Y-m-d H:i:s', $data['timestamp']) : $now,
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
            return $this->updateDevice($device['id'], $deviceData);
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
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateDeviceStatus(string $deviceId, string $status): bool
    {
        $device = $this->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return false;
        }

        $this->beginTransaction();
        try {
            $this->updateDevice($device['id'], [
                'status' => $status,
            ]);
            $this->updateChannelsStatusByDeviceId($deviceId , $status);
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
        return $this->getDeviceChannelsDao()->update(['device_id' => $deviceId], ['status' => $status]);
    }

    public function getChannelById($id)
    {
        return $this->getDeviceChannelsDao()->get($id);
    }

    public function getChannelByDeviceAndChannel(string $deviceId, string $channelId)
    {
        return $this->getDeviceChannelsDao()->getByDeviceAndChannel($deviceId, $channelId);
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
            'status' => 'offline',
            'enabled' => false,
            'media_server_id' => 'default',
            'channel_type' => $this->parseDeviceChanelTypeByDeviceId($fields['channel_id']),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        if (empty($channel['ssrc'])) {
            $channel['ssrc'] = $this->generateUniqueSsrc();
            $channel['main_id'] = $this->ssrcIdToCrc32Hex($channel['ssrc']);
        }

        if (empty($channel['stream_id']) && !empty($channel['device_id']) && !empty($channel['channel_id'])) {
            $channel['stream_id'] = "{$channel['device_id']}_{$channel['channel_id']}";
        }

        return $this->getDeviceChannelsDao()->create($channel);
    }

    public function updateChannel($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getDeviceChannelsDao()->update($id, $fields);
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
                    'status' => ($item['Status'] ?? 'OFF') === 'ON' ? 'online' : 'offline',
                    'lng' => $item['Longitude'] ?? 0.0,
                    'lat' => $item['Latitude'] ?? 0.0,
                    'ssrc' => '',
                    'channel_type' => $this->parseDeviceChanelTypeByDeviceId($channelId),
                ];

                if ($channel) {
                    $this->updateChannel($channel['id'], $channelData);
                } else {
                    $channelData['device_id'] = $deviceId;
                    $channelData['channel_id'] = $channelId;
                    $channelData['created_at'] = date('Y-m-d H:i:s');
                    $channelData['updated_at'] = date('Y-m-d H:i:s');
                    $channels[] = $channelData;
//                $this->createChannel($channelData);
                }

                $count++;
            }

            if (!empty($channels)) {
                $this->getDeviceChannelsDao()->batchCreate($channels);
            }

            $this->updateDevice($device['id'], [
                'sum_num' => $count,
                'device_name' => $count === 1 ? $channels[0]['channel_name'] : $devices['device_name']
            ]);
            $this->commit();

            return $count;
        } catch (\Exception $exception) {
            $this->rollback();
            throw $exception;
        }

    }

    /**
     * 对 SSRC ID 进行 CRC32 哈希，并返回 8 位小写十六进制字符串
     *
     * @param string $ssrcId 输入的 SSRC 标识（建议为字符串）
     * @return string 8 位十六进制字符串（如 'a1b2c3d4'）
     */
    public function ssrcIdToCrc32Hex(string $ssrcId): string
    {
        // 转为字符串（兼容数字输入）
        $input = (string)$ssrcId;

        // 计算 CRC32 并转换为无符号 32 位整数
        $hash = crc32($input);
        $unsigned = sprintf('%u', $hash);

        // 转为十六进制，小写，不足 8 位左补零
        return str_pad(dechex($unsigned), 8, '0', STR_PAD_LEFT);
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
            $channelType = 'invalid_id';
        } else {
            // 提取设备类型码（第11~13位，索引10~12）
            $typeCode = substr($channelId, 10, 3);

            // 根据国标+行业实践映射类型
            switch ($typeCode) {
                case '111':
                case '121':
                case '131':
                case '132':
                    $channelType = 'video';
                    break;
                case '213':
                    $channelType = 'alarm_input';   // 报警输入
                    break;
                case '212':
                    $channelType = 'alarm_output';  // 报警输出
                    break;
                case '211':
                    $channelType = 'alarm_host';    // 报警主机（较少作为子通道）
                    break;
                default:
                    $channelType = 'unknown';       // 其他类型，如音频、智能等
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

    public function cleanupExpiredSessions(int $ttl = 300): int
    {
        $expireTime = date('Y-m-d H:i:s', time() - $ttl);

        return $this->getStreamSessionsDao()->deleteAllByExpireTime($expireTime);

//        return Db::table('gv_stream_sessions')
//            ->where('updated_at', '<', $expireTime)
//            ->whereIn('status', ['inviting', 'ringing'])
//            ->delete();
    }

    // ==================== SSRC 管理 ====================

    public function generateUniqueSsrc(): string
    {
        $maxAttempts = 100;
        $attempt = 0;

        do {
            $ssrc = str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

//            $exists = Db::table('gv_device_channels')
//                ->where('ssrc', $ssrc)
//                ->exists();
            $exists = $this->getDeviceChannelsDao()->existBySsrc($ssrc);

            if (!$exists) {
                return $ssrc;
            }

            $attempt++;
        } while ($attempt < $maxAttempts);

        return substr((string)(time() * 1000 + rand(0, 999)), -10);
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
}
