<?php

namespace CoreW\Business\MediaServer\Strategy;

use CoreW\Business\Devices\Enums\MediaServerType;

/**
 * 媒体服务器策略工厂
 *
 * 根据媒体服务器类型返回对应的策略实例
 */
class MediaServerStrategyFactory
{
    private static array $strategies = [];

    /**
     * 获取媒体服务器策略实例
     *
     * @param string $type 媒体服务器类型 (zlm, srs, other)
     * @return MediaServerStrategyInterface
     * @throws \InvalidArgumentException
     */
    public static function getStrategy(string $type): MediaServerStrategyInterface
    {
        // 标准化类型名称
        $type = strtolower($type);

        // 如果已缓存，直接返回
        if (isset(self::$strategies[$type])) {
            return self::$strategies[$type];
        }

        return match ($type) {
            MediaServerType::ZLM->value => self::$strategies[$type] = new ZLMediaKitStrategy(),
            MediaServerType::SRS->value => self::$strategies[$type] = new SRSMediaServerStrategy(),
            default => throw new \InvalidArgumentException("Unsupported media server type: {$type}"),
        };
    }

    /**
     * 检查类型是否支持
     *
     * @param string $type
     * @return bool
     */
    public static function isSupported(string $type): bool
    {
        $type = strtolower($type);

        return in_array($type, [
            MediaServerType::ZLM->value,
            MediaServerType::SRS->value,
        ]);
    }
}
