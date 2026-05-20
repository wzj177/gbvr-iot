<?php

namespace CoreW\Business\GB;

use Illuminate\Redis\Connections\Connection;
use Redis;
use support\Log;

class Gb28181SendRtpPortService
{

    const SEND_RTP_INFO_KEY = 'GBVRIOT_SEND_RTP_INFO_CALLID';
    const SEND_RTP_PORT_INDEX_KEY = 'GBVRIOT_SEND_RTP_PORT:';

    const SEND_RTP_INFO_STREAM_KEY = 'GBVRIOT_SEND_RTP_INFO_STREAM:';
    const SEND_RTP_INFO_CHANNEL_KEY = 'GBVRIOT_SEND_RTP_INFO_CHANNEL:';

    public function __construct(private Connection|Redis $redis, private string $serverId)
    {
    }

    /**
     * 生成通道唯一键
     *
     * WVP 使用数据库主键，我们使用 deviceId_channelId 组合
     */
    private function buildChannelKey(string $deviceId, string $channelId) : string
    {
        return $deviceId . '_' . $channelId;
    }

    /**
     * 获取下一个可用的发送端口
     *
     * @param array $mediaServer 媒体服务器配置
     * @return int 返回端口号，失败返回 -1
     */
    public function getNextPort(array $mediaServer) : int
    {
        if (empty($mediaServer)) {
            Log::error("[发送端口管理] 参数错误，mediaServer为空");
            return -1;
        }

        $mediaServerId = $mediaServer['id'] ?? '';
        $sendRtpPortRange = $mediaServer['send_rtp_port_range'] ?? null;

        // Redis key，用于记录当前端口索引
        $sendIndexKey = self::SEND_RTP_PORT_INDEX_KEY . ":{$this->serverId}:{$mediaServerId}";

        // 获取当前所有已使用的端口
        $usedPorts = $this->getAllSendRtpPort();

        // 解析端口范围
        $startPort = 50000;
        $endPort = 60000;

        if ($sendRtpPortRange !== null) {
            $portArray = explode(',', $sendRtpPortRange);
            if (count($portArray) === 2 && is_numeric($portArray[0]) && is_numeric($portArray[1])) {
                $start = (int)$portArray[0];
                $end = (int)$portArray[1];
                if ($end - $start >= 1) {
                    $startPort = $start;
                    $endPort = $end;
                } else {
                    Log::error("{$mediaServerId} 发送端口配置错误，结束端口至少比开始端口大一");
                }
            } else {
                Log::error("{$mediaServerId} 发送端口配置格式错误");
            }
        } else {
            Log::error("{$mediaServerId} 未设置发送端口默认值");
        }

        // 获取当前端口索引
        $currentPort = (int)$this->redis->get($sendIndexKey);

        if ($currentPort < $startPort) {
            // 初始化，从起始端口开始
            $this->redis->set($sendIndexKey, $startPort);
            return $startPort;
        }

        // 循环查找一个未使用的端口
        $totalPorts = $endPort - $startPort;
        for ($i = 0; $i < $totalPorts; $i++) {
            // 原子递增
            $port = $this->redis->incr($sendIndexKey);

            if ($port > $endPort) {
                // 超过范围，回绕到起始端口
                $this->redis->set($sendIndexKey, $startPort);

                if (!in_array($startPort, $usedPorts)) {
                    return $startPort;
                }
                continue;
            }

            // 检查端口是否已被占用
            if (!in_array($port, $usedPorts)) {
                return $port;
            }
        }

        Log::error("{$mediaServerId} 获取发送端口失败，无可用端口");
        return -1;
    }

    /**
     * 获取所有已使用的发送端口
     *
     * @return array
     */
    private function getAllSendRtpPort() : array
    {
        $key = self::SEND_RTP_INFO_KEY;
        $values = $this->redis->hGetAll($key);

        $ports = [];
        foreach ($values as $value) {
            $session = json_decode($value, true);
            if (isset($session['port'])) {
                $ports[] = (int)$session['port'];
            }
        }

        return $ports;
    }


    /**
     * 缓存 SendRtpInfo（标记端口已使用）
     */
    public function saveSendRtpInfo(array $session) : void
    {
        $callId = $session['call_id'] ?? '';
        $stream = $session['stream'] ?? '';
        $channelId = $session['channel_id'] ?? '';
        $deviceId = $session['device_id'] ?? '';

        if (empty($callId)) {
            return;
        }

        $json = json_encode($session);

        // 存储到 Redis Hash
        $this->redis->hSet(self::SEND_RTP_INFO_KEY, $callId, $json);

        if ($stream) {
            $this->redis->hSet(self::SEND_RTP_INFO_STREAM_KEY . $stream, $deviceId, $json);
        }

        if ($deviceId && $channelId) {
            $channelKey = $this->buildChannelKey($deviceId, $channelId);
            $this->redis->hSet(self::SEND_RTP_INFO_CHANNEL_KEY . $channelKey, $deviceId, $json);
        }
    }

    /**
     * 删除 SendRtpInfo（释放端口）
     */
    public function deleteSendRtpInfo(array $session) : void
    {
        $callId = $session['call_id'] ?? '';
        $stream = $session['stream'] ?? '';
        $channelId = $session['channel_id'] ?? '';
        $deviceId = $session['device_id'] ?? '';

        if ($callId) {
            $this->redis->hDel(self::SEND_RTP_INFO_KEY, $callId);
        }

        if ($stream) {
            $this->redis->hDel(self::SEND_RTP_INFO_STREAM_KEY . $stream, $deviceId);
        }

        if ($deviceId && $channelId) {
            $channelKey = $this->buildChannelKey($deviceId, $channelId);
            $this->redis->hDel(self::SEND_RTP_INFO_CHANNEL_KEY . $channelKey, $deviceId);
        }


        $prevSendIndexKey = self::SEND_RTP_PORT_INDEX_KEY . ":{$this->serverId}:{$session['media_server_id']}";
        $this->redis->del($prevSendIndexKey);
    }

    /**
     * 根据 CallId 查询
     */
    public function queryByCallId(string $callId) : ?array
    {
        $json = $this->redis->hGet(self::SEND_RTP_INFO_KEY, $callId);
        return $json ? json_decode($json, true) : null;
    }

    /**
     * 根据 Stream 查询
     */
    public function queryByStream(string $stream, string $targetId) : ?array
    {
        $json = $this->redis->hGet(self::SEND_RTP_INFO_STREAM_KEY . $stream, $targetId);
        return $json ? json_decode($json, true) : null;
    }

    public function queryByDeviceIdAndChannelId(string $deviceId, string $channelId) : ?array
    {
        $channelKey = $this->buildChannelKey($deviceId, $channelId);
        $json = $this->redis->hGet(self::SEND_RTP_INFO_CHANNEL_KEY . $channelKey, $deviceId);
        return $json ? json_decode($json, true) : null;
    }
}