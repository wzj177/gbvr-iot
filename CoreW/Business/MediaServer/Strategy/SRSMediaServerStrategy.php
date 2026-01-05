<?php

namespace CoreW\Business\MediaServer\Strategy;

use support\Log;

/**
 * SRS 流媒体服务器策略实现
 *
 * TODO: 待实现
 */
class SRSMediaServerStrategy implements MediaServerStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function getStats(array $serverConfig): array
    {
        Log::warning('SRS strategy not implemented yet', [
            'host' => $serverConfig['host'] ?? '',
        ]);

        return [
            'running' => false,
            'status' => 'unknown',
            'error' => 'SRS strategy not implemented yet',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(array $serverConfig): array
    {
        throw new \RuntimeException('SRS strategy not implemented yet');
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(array $serverConfig, array $config): bool
    {
        throw new \RuntimeException('SRS strategy not implemented yet');
    }

    /**
     * {@inheritdoc}
     */
    public function restart(array $serverConfig): bool
    {
        throw new \RuntimeException('SRS strategy not implemented yet');
    }

    /**
     * {@inheritdoc}
     */
    public function isOnline(array $serverConfig): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(array $serverConfig): ?string
    {
        return null;
    }
}
