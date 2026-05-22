<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface VoiceSessionDao extends AdvancedDaoInterface
{

    public function getBySessionId(string $sessionId) : ?array;

    public function getByDeviceAndChannel(string $deviceId, string $channelId) : ?array;

    public function getByDialogId(string $dialogId) : array|false;

    public function getByStream(string $stream) : array|false;

    public function getByStreamAndMediaServerId(string $stream, string $mediaServerId) : array|false;

    public function getByNoEndedStreamAndMediaServerId(string $stream, string $mediaServerId) : array|false;

    public function updStatus(int $id, string $status);

    public function updateStatusBySessionId(string $sessionId, string $status) : bool;

    public function getByCallId(string $callId) : array|false;

    /**
     * 获取所有活跃会话
     *
     * @param string|null $deviceId 可选，过滤指定设备
     * @return array
     */
    public function getActiveSessions(?string $deviceId = null) : array;

    public function getActiveByDeviceAndChannel(
        string $deviceId,
        string $channelId,
        int $timeoutSeconds = 30
    ) : array|false;

    /**
     * 查找同设备同通道、指定模式的活跃会话（带时间范围，排除指定 session）
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $mode 模式（talk/broadcast）
     * @param int $timeoutSeconds 有效时间窗口（秒）
     * @param string|null $excludeSessionId 要排除的 session_id
     * @return array 匹配的会话列表
     */
    public function findActiveByDeviceChannelAndMode(
        string $deviceId,
        string $channelId,
        string $mode,
        int $timeoutSeconds = 30,
        ?string $excludeSessionId = null
    ) : array;

    /**
     * 根据 SSRC 获取会话
     *
     * @param string $ssrc
     * @return array|false
     */
    public function getBySsrc(string $ssrc) : array|false;

    /**
     * CAS (Compare-And-Set) 更新状态
     * 只有当前状态为 expectedStatus 时才更新为 newStatus
     * 使用乐观锁 version 字段防止并发问题
     *
     * @param int $id 会话ID
     * @param string $expectedStatus 期望的当前状态
     * @param string $newStatus 要更新的新状态
     * @param array $extraFields 额外要更新的字段（如 ended_reason）
     * @return bool 更新成功返回 true，状态不匹配返回 false
     */
    public function updStatusIf(int $id, string $expectedStatus, string $newStatus, array $extraFields = []) : bool;

    /**
     * 查找超时的会话
     * 用于定时任务处理超时的会话
     *
     * @param int $limit 限制返回数量
     * @return array 超时会话列表
     */
    public function findExpiredSessions(int $limit = 100) : array;

    /**
     * 标记会话为结束状态（带CAS）
     *
     * @param int $id 会话ID
     * @param string $endedReason 结束原因
     * @return bool
     */
    public function markAsEnded(int $id, string $endedReason = 'manual') : bool;

    /**
     * 结束会话（任意非 ENDED 状态 -> ENDED）
     *
     * @param int $id 会话ID
     * @param string $endedReason 结束原因
     * @return bool 更新成功返回 true，已是 ENDED 返回 false
     */
    public function endSession(int $id, string $endedReason = 'manual') : bool;

}
