<?php

namespace CoreW\Business\Devices\Service;

interface DeviceService
{
    // 设备基础操作
    public function getDevicesById($id);
    public function getDeviceByDeviceId(string $deviceId);
    public function countDevices(array $conditions);
    public function searchDevices(array $conditions, array $orderBys, $start, $limit, $columns = []);
    public function createDevice(array $fields);
    public function updateDevice($id, array $fields);
    public function deleteDeviceById($id);

    // 设备注册相关
    public function handleDeviceRegister(string $deviceId, array $data): array;
    public function updateDeviceHeartbeat(string $deviceId): bool;
    public function updateDeviceStatus(string $deviceId, string $status): bool;

    // 设备通道操作
    public function getChannelById($id);
    public function getChannelByDeviceAndChannel(string $deviceId, string $channelId);
    public function searchChannels(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function getChannelsByDeviceId(string $deviceId);

    public function createChannel(array $fields);
    public function updateChannel($id, array $fields);
    public function updateChannelByMainId(string $mainId, array $fields);
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
}