<?php

namespace CoreW\Business\Devices\Service;

interface DeviceService
{
    // 设备基础操作
    public function getDevicesById($id);

    public function getDeviceByDeviceId(string $deviceId);

    public function countDevices(array $conditions);

    public function searchDevices(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function findDevicesByDeviceIds(array $deviceIds) : array;

    public function summaryDevices(array $conditions) : array;

    public function createDevice(array $fields);

    public function updateDevice($id, array $fields);

    public function updateDeviceExtendInfo($id, array $fields);

    public function deleteDeviceById($id);

    // 设备注册相关
    public function handleDeviceRegister(string $deviceId, array $data) : array;

    public function updateDeviceHeartbeat(string $deviceId) : bool;

    public function updateDeviceStatus(string $deviceId, string $status) : bool;

    // 设备通道操作
    public function getChannelById($id);

    public function getChannelByDeviceAndChannel(string $deviceId, string $channelId);


    public function countChannels(array $conditions);

    public function searchChannels(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function getChannelsByDeviceId(string $deviceId);

    public function createChannel(array $fields);

    public function updateChannel($id, array $fields);

    public function updateChannelByMainId(string $mainId, array $fields);

    public function batchUpdateChannels(array $ids, array $fields) : int;

    public function batchUpdateOrCreateChannels(string $deviceId, array $devices) : int;

    public function deleteChannel($id) : bool;

    // 流会话操作
    public function getSessionById($id);

    public function getSessionByCallId(int $callId);

    public function getSessionBySsrc(string $ssrc);

    public function getSessionByStreamId(string $streamId);

    public function getActiveSessionByStreamIdAndType(string $streamId, string $type);

    public function incrementSessionViewerCount(string $streamId);

    public function decrementSessionViewerCount(string $streamId);

    /**
     * CAS（Compare-And-Set）递减 viewer_count（乐观锁）
     *
     * 用于替代悲观锁：仅当 viewer_count > 1 时才递减
     * 返回数组：['action' => 'decremented' | 'closed' | 'not_found']
     *
     * @param string $streamId 流ID
     * @param string $type 会话类型
     * @return array ['action' => string, 'affected' => int]
     */
    public function casDecrementSessionViewerCount(string $streamId, string $type) : array;

    public function createSession(array $fields);

    public function updateSession($id, array $fields);

    public function updateSessionByCallId(int $callId, array $fields) : bool;

    public function updateSessionBySSRC(string $ssrc, array $fields);

    public function deleteSession($id);

    public function deleteSessionByCallId(int $callId) : bool;

    public function deleteSessionByStreamIdAndMediaServerId(string $streamId, string $mediaServerId);

    public function cleanupExpiredSessions(int $ttl = 300) : int;

    public function countSessions(array $conditions) : int;

    public function searchSessions(array $conditions, array $orderBys, $start, $limit, $columns = []) : array;

    public function batchDeleteSessions(array $ids);

    // SSRC 管理
    public function generateUniqueSsrc() : string;

    // 端口管理
    public function getCoolingPorts(int $coolingTime = 20) : array;

    // 树形数据
    public function getDeviceTree(string $treeType = 'dc') : array;

    // ==================== 推送相关 ====================

    /**
     * 获取推送设备列表（包含订阅配置）
     * 用于 Gateway 启动时恢复设备状态
     *
     * @param array $deviceIds 设备ID列表
     * @return array 设备列表，每个设备包含 subscription_status 字段
     */
    public function getDevicesForPush(array $deviceIds) : array;

    // ==================== 自动直播 ====================

    /**
     * 获取所有配置了自动直播的视频通道
     * @return array
     */
    public function getAutoLiveChannels() : array;

    /**
     * 清除指定录像计划绑定的所有通道
     */
    public function clearChannelRecordPlan(int $planId) : int;

    // ==================== 预置位管理 ====================

    /**
     * 获取设备和通道的预置位列表
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @return array 预置位列表
     */
    public function getPresetList(string $deviceId, string $channelId) : array;

    /**
     * 设置预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @param string $name 预置位名称
     * @return array 创建的预置位记录
     */
    public function setPreset(string $deviceId, string $channelId, int $value, string $name = '') : array;

    /**
     * 调用预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @return bool
     */
    public function callPreset(string $deviceId, string $channelId, int $value) : bool;

    /**
     * 删除预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @return bool
     */
    public function deletePreset(string $deviceId, string $channelId, int $value) : bool;

    /**
     * 更新设备下所有通道的位置信息（设备同步的经纬度）
     * @param string $deviceId 设备ID
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @return int 更新的通道数量
     */
    public function updateDeviceChannelsPosition(string $deviceId, float $longitude, float $latitude) : int;

    /**
     * 更新移动设备的位置信息（MobilePosition订阅）
     * @param string $deviceId 设备ID
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @return int 更新的设备数量（0或1）
     */
    public function updateDevicePosition(string $deviceId, float $longitude, float $latitude) : int;

    /**
     * 更新设备分类
     *
     * @param string $deviceId 设备ID
     * @param int|null $categoryCode 设备分类编码（传null则自动从device_id解析）
     * @return bool
     */
    public function updateDeviceCategory(string $deviceId, ?int $categoryCode = null) : bool;

    /**
     * 批量更新设备分类（从device_id自动解析）
     *
     * @param array $deviceIds 设备ID列表（为空则更新所有设备）
     * @return int 更新的设备数量
     */
    public function batchUpdateDeviceCategories(array $deviceIds = []) : int;

    /**
     * 绑定设备到SIP网关
     *
     * @param string $deviceId 设备ID
     * @param string $gatewayId 网关ID
     * @return bool
     */
    public function bindDeviceToGateway(string $deviceId, string $gatewayId) : bool;
}