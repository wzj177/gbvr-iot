<?php

namespace CoreW\Business\MediaServer\Strategy;

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
    public function getStats(array $serverConfig): array;

    /**
     * 获取服务器配置
     *
     * @param array $serverConfig 服务器配置信息
     * @return array 配置数据
     */
    public function getConfig(array $serverConfig): array;

    /**
     * 设置服务器配置
     *
     * @param array $serverConfig 服务器配置信息
     * @param array $config 要设置的配置
     * @return bool
     */
    public function setConfig(array $serverConfig, array $config): bool;

    /**
     * 重启服务器
     *
     * @param array $serverConfig 服务器配置信息
     * @return bool
     */
    public function restart(array $serverConfig): bool;

    /**
     * 检查服务器是否在线
     *
     * @param array $serverConfig 服务器配置信息
     * @return bool
     */
    public function isOnline(array $serverConfig): bool;

    /**
     * 获取服务器版本信息
     *
     * @param array $serverConfig 服务器配置信息
     * @return array|null
     */
    public function getVersion(array $serverConfig): ?array;
}
