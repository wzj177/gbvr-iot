<?php

namespace CoreW\Business\GB;


use Illuminate\Contracts\Redis\Connection;
use \Redis;
use RuntimeException;

/**
 * SSRC 使用工厂
 */
class SSRCFactory
{
    /**
     * 播流最大并发个数
     * 注意：不能超过 10000，否则 sprintf('%04d', $i) 会产生 5+ 位数字，
     * 导致 SSRC 超过 GB28181 规定的 10 位格式（1位类型 + 5位域编码 + 4位序列号）
     */
    private const MAX_STREAM_COUNT = 10000;

    /**
     * Redis Key 前缀
     */
    private const SSRC_INFO_KEY = 'GBVRIOT_SSRC_INFO_';

    private Connection|Redis $redis;

    private array $sipConfig;
    private string $appId;

    public function __construct(Connection|Redis $redis, array $sipConfig, string $appId)
    {
        $this->redis = $redis;
        $this->sipConfig = $sipConfig;
        $this->appId = $appId;
    }

    /**
     * 初始化一个流媒体服务器的SSRC池
     */
    public function initMediaServerSSRC(string $mediaServerId, ?array $usedSet = null) : void
    {
        $sipDomain = $this->sipConfig['server_domain'];

        if (strlen($sipDomain) >= 8) {
            $ssrcPrefix = substr($sipDomain, 3, 5);
        } else {
            $ssrcPrefix = $sipDomain;
        }

        $redisKey = $this->getRedisKey($mediaServerId);

        // 删除旧集合
        if ($this->redis->exists($redisKey)) {
            $this->redis->del($redisKey);
        }

        $pipe = $this->redis->multi(Redis::PIPELINE);

        for ($i = 1; $i < self::MAX_STREAM_COUNT; $i++) {
            $sn = sprintf('%s%04d', $ssrcPrefix, $i);

            if ($usedSet === null || !in_array($sn, $usedSet, true)) {
                $pipe->sAdd($redisKey, $sn);
            }
        }

        $pipe->exec();
    }

    /**
     * 获取视频预览SSRC（0开头）
     */
    public function getPlaySsrc(string $mediaServerId) : string
    {
        return '0' . $this->getSN($mediaServerId);
    }

    /**
     * 获取录像回放SSRC（1开头）
     */
    public function getPlayBackSsrc(string $mediaServerId) : string
    {
        return '1' . $this->getSN($mediaServerId);
    }


    /**
     * 获取对讲流 SSRC（2开头）
     */
    public function getTalkSsrc(string $mediaServerId) : ?string
    {
        try {
            return '2' . $this->getSN($mediaServerId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取广播流 SSRC（3开头）
     */
    public function getBroadcastSsrc(string $mediaServerId) : string
    {
        return '3' . $this->getSN($mediaServerId);
    }

    /**
     * 获取下载流 SSRC（4开头）
     */
    public function getDownloadSsrc(string $mediaServerId) : string
    {
        return '4' . $this->getSN($mediaServerId);
    }

    /**
     * 释放SSRC - 重新放回到池中
     */
    public function releaseSsrc(string $mediaServerId, ?string $ssrc) : void
    {
        if ($ssrc === null) {
            return;
        }

        $sn = substr($ssrc, 1);
        $redisKey = $this->getRedisKey($mediaServerId);

        $this->redis->sAdd($redisKey, $sn);
    }

    /**
     * 获取后四位SN
     */
    private function getSN(string $mediaServerId) : string
    {
        $redisKey = $this->getRedisKey($mediaServerId);
        if (!$this->redis->exists($redisKey)) {
            $this->initMediaServerSSRC($mediaServerId);
        }

        $size = $this->redis->sCard($redisKey);

        if ($size === 0) {
            throw new RuntimeException("SSRC已经用完: {$redisKey}");
        }

        // 等价 redisTemplate.opsForSet().pop()
        $sn = $this->redis->sPop($redisKey);

        if ($sn === false) {
            throw new RuntimeException("获取SSRC失败: {$redisKey}");
        }

        return (string)$sn;
    }

    /**
     * 重置某个流媒体服务SSRC
     */
    public function reset(string $mediaServerId) : void
    {
        $this->initMediaServerSSRC($mediaServerId, null);
    }

    public function removeMediaServerSSRC(string $mediaServerId) : void
    {
        $redisKey = $this->getRedisKey($mediaServerId);

        if ($this->redis->exists($redisKey)) {
            $this->redis->del($redisKey);
        }
    }

    /**
     * 是否存在某MediaServer的SSRC池
     */
    public function hasMediaServerSSRC(string $mediaServerId) : bool
    {
        return $this->redis->exists(
                $this->getRedisKey($mediaServerId)
            ) > 0;
    }

    /**
     * 生成Redis Key
     */
    private function getRedisKey(string $mediaServerId) : string
    {
        return self::SSRC_INFO_KEY
            . $this->appId
            . '_'
            . $mediaServerId;
    }
}
