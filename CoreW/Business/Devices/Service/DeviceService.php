<?php

namespace CoreW\Business\Devices\Service;

interface DeviceService
{
    // 设备基础操作
    public function getDevicesById($id);
    public function getDeviceByDeviceId(string $deviceId);
    public function countDevices(array $conditions);
    public function searchDevices(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function summaryDevices(array $conditions): array;
    public function createDevice(array $fields);
    public function updateDevice($id, array $fields);

    public function updateDeviceExtendInfo($id, array $fields);
    public function deleteDeviceById($id);

    // 设备注册相关
    public function handleDeviceRegister(string $deviceId, array $data): array;
    public function updateDeviceHeartbeat(string $deviceId): bool;
    public function updateDeviceStatus(string $deviceId, string $status): bool;

    // 设备通道操作
    public function getChannelById($id);
    public function getChannelByDeviceAndChannel(string $deviceId, string $channelId);


    public function countChannels(array $conditions);
    public function searchChannels(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function getChannelsByDeviceId(string $deviceId);

    public function createChannel(array $fields);
    public function updateChannel($id, array $fields);
    public function updateChannelByMainId(string $mainId, array $fields);
    public function batchUpdateChannels(array $ids, array $fields): int;
    public function batchUpdateOrCreateChannels(string $deviceId, array $devices): int;

    // 流会话操作
    public function getSessionById($id);
    public function getSessionByCallId(int $callId);
    public function getSessionBySsrc(string $ssrc);
    public function getSessionByStreamId(string $streamId);
    public function createSession(array $fields);
    public function updateSession($id, array $fields);
    public function updateSessionByCallId(int $callId, array $fields): bool;
    public function deleteSession($id);
    public function deleteSessionByCallId(int $callId): bool;
    public function cleanupExpiredSessions(int $ttl = 300): int;

    // SSRC 管理
    public function generateUniqueSsrc(): string;

    // 端口管理
    public function getCoolingPorts(int $coolingTime = 20): array;

    // 树形数据
    public function getDeviceTree(string $treeType = 'dc'): array;

    // ==================== 预置位管理 ====================

    /**
     * 获取设备和通道的预置位列表
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @return array 预置位列表
     */
    public function getPresetList(string $deviceId, string $channelId): array;

    /**
     * 设置预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @param string $name 预置位名称
     * @return array 创建的预置位记录
     */
    public function setPreset(string $deviceId, string $channelId, int $value, string $name = ''): array;

    /**
     * 调用预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @return bool
     */
    public function callPreset(string $deviceId, string $channelId, int $value): bool;

    /**
     * 删除预置位
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $value 预置位编号 (1-255)
     * @return bool
     */
    public function deletePreset(string $deviceId, string $channelId, int $value): bool;
}