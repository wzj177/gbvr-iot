<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Business\Devices\Dao\VoiceSessionDao;
use CoreW\Dao\AdvancedDaoImpl;

class VoiceSessionDaoImpl extends AdvancedDaoImpl implements VoiceSessionDao
{
    protected $table = 'gv_voice_sessions';

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => ['id', 'created_at', 'started_at'],
            'datetime' => [
                'created_at',
                'updated_at',
                'started_at',
                'ended_at',
                'expires_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'session_id = :session_id',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'channel_id = :channel_id',
                'dialog_id = :dialog_id',
                'stream = :stream',
                'media_server_id = :media_server_id',
                'status = :status',
                'status IN (:statuses)',
                'mode = :mode',
                'ssrc = :ssrc',
                'expires_at < :expires_before',
                'status != :status_not',
            ],
        ];
    }



    /**
     * 根据设备和通道获取活跃会话（
     *
     * @param string $deviceId
     * @param string $channelId
     * @param int $timeoutSeconds 超时时间（秒），默认 300 秒（5分钟）
     * @return array|false
     */
    public function getActiveByDeviceAndChannel(
        string $deviceId,
        string $channelId,
        int $timeoutSeconds = 300
    ): array|false {
        // 计算超时时间点
        $timeoutAt = date('Y-m-d H:i:s', time() - $timeoutSeconds);
        $sql = "SELECT * FROM {$this->table()} 
            WHERE device_id = ? 
            AND channel_id = ? 
            AND status IN ('waiting_stream', 'stream_arrived', 'inviting', 'established') 
            AND created_at >= ?
            LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$deviceId, $channelId, $timeoutAt]);
    }



    /**
     * 根据 stream 获取会话
     *
     * @param string $stream 格式: talk/streamId 或 broadcast/streamId
     * @return array|false
     */
    public function getByStream(string $stream): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE stream = ? LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$stream]);
    }

    public function getByStreamAndMediaServerId(string $stream, string $mediaServerId): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE stream = ? AND media_server_id = ? LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$stream, $mediaServerId]);
    }

    public function getByNoEndedStreamAndMediaServerId(string $stream, string $mediaServerId): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE stream = ? AND media_server_id = ? AND `status` <> ? IS NULL LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$stream, $mediaServerId, 'ended']);
    }

    /**
     * 根据 dialog_id 获取会话
     *
     * @param string $dialogId
     * @return array|false
     */
    public function getByDialogId(string $dialogId): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE dialog_id = ? LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$dialogId]);
    }

    /**
     * 根据 call_id 获取会话
     *
     * @param string $callId
     * @return array|false
     */
    public function getByCallId(string $callId): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE call_id = ? LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$callId]);
    }

    /**
     * 获取所有活跃会话
     *
     * @param string|null $deviceId 可选，过滤指定设备
     * @return array
     */
    public function getActiveSessions(?string $deviceId = null): array
    {
        if ($deviceId) {
            $sql = "SELECT * FROM {$this->table()} 
                    WHERE device_id = ? 
                    AND status IN ('waiting_stream', 'stream_arrived', 'inviting', 'established') 
                    ORDER BY id DESC";

            return $this->db()->fetchAll($sql, [$deviceId]);
        }

        $sql = "SELECT * FROM {$this->table()} 
                WHERE status IN ('waiting_stream', 'stream_arrived', 'inviting', 'established') 
                ORDER BY id DESC";

        return $this->db()->fetchAll($sql);
    }

    public function getBySessionId(string $sessionId): ?array
    {
        return $this->getByFields(['session_id' => $sessionId]);
    }

    public function getByDeviceAndChannel(string $deviceId, string $channelId): ?array
    {
        return $this->getByFields([
            'device_id' => $deviceId,
            'channel_id' => $channelId,
        ]);
    }


    public function updStatus(int $id, string $status)
    {
        return $this->update($id, [
            'status' => $status,
        ]);
    }

    public function updateStatusBySessionId(string $sessionId, string $status): bool
    {
        $session = $this->getBySessionId($sessionId);
        if (!$session) {
            return false;
        }
        return $this->updStatus($session['id'], $status);
    }

    /**
     * 根据 SSRC 获取会话
     */
    public function getBySsrc(string $ssrc): array|false
    {
        return $this->getByFields(['ssrc' => $ssrc]);
    }

    /**
     * CAS (Compare-And-Set) 更新状态
     *
     * 使用 SQL 的 UPDATE ... WHERE ... AND version = ?
     * 确保只有当前状态匹配时才更新，防止并发问题
     *
     * @param int $id 会话ID
     * @param string $expectedStatus 期望的当前状态
     * @param string $newStatus 要更新的新状态
     * @param array $extraFields 额外要更新的字段
     * @return bool 更新成功返回 true
     */
    public function updateStatusIf(int $id, string $expectedStatus, string $newStatus, array $extraFields = []): bool
    {
        $sql = "UPDATE {$this->table()}
                SET status = :new_status,
                    version = version + 1,
                    updated_at = :updated_at";

        $params = [
            'id' => $id,
            'expected_status' => $expectedStatus,
            'new_status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 添加额外字段
        foreach ($extraFields as $field => $value) {
            $sql .= ", {$field} = :{$field}";
            $params[$field] = $value;
        }

        $sql .= " WHERE id = :id AND status = :expected_status";

        $affectedRows = $this->db()->executeStatement($sql, $params);

        return $affectedRows > 0;
    }

    /**
     * 查找超时的会话
     *
     * @param int $limit
     * @return array
     */
    public function findExpiredSessions(int $limit = 100): array
    {
        $sql = "SELECT * FROM {$this->table()}
                WHERE status IN ('waiting_stream', 'inviting')
                AND expires_at < NOW()
                LIMIT :limit";

        return $this->db()->fetchAll($sql, ['limit' => $limit]);
    }

    /**
     * 标记会话为结束状态（带CAS）
     *
     * @param int $id
     * @param string $endedReason
     * @return bool
     */
    public function markAsEnded(int $id, string $endedReason = 'manual'): bool
    {
        return $this->updateStatusIf(
            $id,
            'failed', // 只能从 FAILED 状态转换到 ENDED
            'ended',
            [
                'ended_reason' => $endedReason,
                'ended_at' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
