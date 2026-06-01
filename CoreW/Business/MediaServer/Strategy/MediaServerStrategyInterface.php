<?php

namespace CoreW\Business\MediaServer\Strategy;

use CoreW\Business\BizEnum;

/**
 * 媒体服务器策略接口
 *
 * 用于支持不同类型的流媒体服务器（ZLMediaKit、SRS等）
 */
interface MediaServerStrategyInterface
{
    /**
     * 获取服务器状态统计信息
     *
     * @param array $serverConfig 服务器配置信息
     * @return array 状态数据
     */
    public function getStats(array $serverConfig) : array;

    /**
     * 获取服务器配置
     *
     * @param array $serverConfig 服务器配置信息
     * @return array 配置数据
     */
    public function getConfig(array $serverConfig) : array;

    /**
     * 设置服务器配置
     *
     * @param array $serverConfig 服务器配置信息
     * @param array $config 要设置的配置
     * @return bool
     */
    public function setConfig(array $serverConfig, array $config) : bool;

    /**
     * 重启服务器
     *
     * @param array $serverConfig 服务器配置信息
     * @return bool
     */
    public function restart(array $serverConfig) : bool;

    /**
     * 检查服务器是否在线
     *
     * @param array $serverConfig 服务器配置信息
     * @return bool
     */
    public function isOnline(array $serverConfig) : bool;

    /**
     * 获取服务器版本信息
     *
     * @param array $serverConfig 服务器配置信息
     * @return array|null
     */
    public function getVersion(array $serverConfig) : ?array;

    /**
     * 添加流代理（拉流）
     *
     * @param array $serverConfig 服务器配置信息
     * @param array $proxyConfig 流代理配置
     *   - vhost: 虚拟主机
     *   - app: 应用名
     *   - stream: 流ID
     *   - url: 源地址
     *   - retry_count: 重试次数
     *   - rtp_type: RTSP RTP传输类型
     *   - timeout_sec: 超时时间
     *   - enable_hls: 是否转换HLS
     *   - enable_mp4: 是否录制MP4
     * @return array ['success' => bool, 'key' => string, 'message' => string]
     */
    public function addStreamProxy(array $serverConfig, array $proxyConfig) : array;

    /**
     * 删除流代理
     *
     * @param array $serverConfig 服务器配置信息
     * @param string $key 流代理唯一标识
     * @return bool
     */
    public function delStreamProxy(array $serverConfig, string $key) : bool;

    /**
     * 检查流是否在线
     *
     * @param array $serverConfig 服务器配置信息
     * @param string $app 应用名
     * @param string $stream 流ID
     * @param string $vhost 虚拟主机（可选）
     * @return bool
     */
    public function isStreamOnline(array $serverConfig, string $app, string $stream, string $vhost = BizEnum::ZLM_DEFAULT_VHOST) : bool;
}
