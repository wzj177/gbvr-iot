<?php

namespace CoreW\Business\MediaServer\Strategy;

use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;

/**
 * ZLMediaKit 流媒体服务器策略实现
 */
class ZLMediaKitStrategy implements MediaServerStrategyInterface
{
    private ?ZLMClient $client = null;

    /**
     * {@inheritdoc}
     */
    public function getStats(array $serverConfig): array
    {
        $client = $this->getClient($serverConfig);
        $timestamp = time();

        try {
            // 获取版本信息
            $version = $this->getVersion($serverConfig);

            // 获取网络线程负载（用于 Echart 折线图）
            $loadResp = $client->getThreadsLoad();

            // 获取工作线程负载（用于 Echart 折线图）
            $workResp = $client->getWorkThreadsLoad();

            // 获取媒体列表（流统计）
            $mediaListResp = $client->getMediaList();

            // 获取对象统计信息
            $statResp = $client->getStatistic();

            // 解析版本数据
            $running = false;
            $branchName = '';
            $buildDate = '';
            $gitHash = '';
            if (!empty($version)) {
                $running = true;
                $branchName = $version['branchName'] ?? '';
                $buildDate = $version['buildTime'] ?? '';
                $gitHash = $version['commitHash'] ?? '';
            }

            // 处理网络线程负载数据 - 转换为 Echart 折线图格式
            $threadLoadData = [];
            if ($loadResp && ($loadResp['code'] ?? -1) === 0 && !empty($loadResp['data'])) {
                foreach ($loadResp['data'] as $index => $thread) {
                    $threadLoadData[] = [
                        'timestamp' => $timestamp,
                        'thread_index' => $index,
                        'thread_name' => $thread['name'] ?? "thread_{$index}",
                        'load' => $thread['load'] ?? 0,
                        'delay' => $thread['delay'] ?? 0,
                        'fd_count' => $thread['fd_count'] ?? 0,
                    ];
                }
            }

            // 处理工作线程负载数据 - 转换为 Echart 折线图格式
            $workThreadLoadData = [];
            if ($workResp && ($workResp['code'] ?? -1) === 0 && !empty($workResp['data'])) {
                foreach ($workResp['data'] as $index => $thread) {
                    $workThreadLoadData[] = [
                        'timestamp' => $timestamp,
                        'thread_index' => $index,
                        'thread_name' => $thread['name'] ?? "work_thread_{$index}",
                        'load' => $thread['load'] ?? 0,
                        'delay' => $thread['delay'] ?? 0,
                        'fd_count' => $thread['fd_count'] ?? 0,
                    ];
                }
            }

            // 处理媒体流统计
            $streamCount = 0;
            $totalReaderCount = 0;
            $totalBytesSpeed = 0;
            if ($mediaListResp && ($mediaListResp['code'] ?? -1) === 0 && !empty($mediaListResp['data'])) {
                $streamCount = count($mediaListResp['data']);
                foreach ($mediaListResp['data'] as $media) {
                    $totalReaderCount += $media['totalReaderCount'] ?? 0;
                    $totalBytesSpeed += $media['bytesSpeed'] ?? 0;
                }
            }

            // 处理对象统计
            $statistics = [];
            if ($statResp && ($statResp['code'] ?? -1) === 0 && !empty($statResp['data'])) {
                $statistics = $statResp['data'];
            }

            // 计算平均负载
            $avgThreadLoad = 0;
            if (!empty($threadLoadData)) {
                $totalLoad = array_sum(array_column($threadLoadData, 'load'));
                $avgThreadLoad = round($totalLoad / count($threadLoadData), 2);
            }

            $avgWorkThreadLoad = 0;
            if (!empty($workThreadLoadData)) {
                $totalLoad = array_sum(array_column($workThreadLoadData, 'load'));
                $avgWorkThreadLoad = round($totalLoad / count($workThreadLoadData), 2);
            }

            return [
                // 服务状态
                'running' => $running,
                'status' => $running ? 'running' : 'stopped',
                'version' => $branchName,
                'build_date' => $buildDate,
                'git_hash' => $gitHash,

                // 当前快照数据（用于仪表盘显示）
                'snapshot' => [
                    'cpu_usage' => max($avgThreadLoad, $avgWorkThreadLoad), // 使用线程负载作为 CPU 使用率参考
                    'memory_usage' => 0, // ZLM 不直接提供内存使用率，需要从系统获取
                    'stream_count' => $streamCount,
                    'total_connection_count' => $totalReaderCount,
                    'bytes_speed' => $totalBytesSpeed,
                    'network_thread_count' => count($threadLoadData),
                    'work_thread_count' => count($workThreadLoadData),
                ],

                // 网络线程负载数据（用于 Echart 折线图）
                'thread_load' => [
                    'data' => $threadLoadData,
                    'timestamp' => $timestamp,
                ],

                // 工作线程负载数据（用于 Echart 折线图）
                'work_thread_load' => [
                    'data' => $workThreadLoadData,
                    'timestamp' => $timestamp,
                ],

                // 对象统计
                'statistics' => $statistics,
            ];
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to get ZLM stats', [
                'host' => $serverConfig['host'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return [
                'running' => false,
                'status' => 'unknown',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(array $serverConfig): array
    {
        $client = $this->getClient($serverConfig);

        try {
            $config = $client->getServerConfig(true);
            if ($config) {
                return $config;
            }

            throw new \RuntimeException('Failed to get server config');
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to get ZLM config', [
                'host' => $serverConfig['host'] ?? '',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(array $serverConfig, array $config): bool
    {
        $client = $this->getClient($serverConfig);

        try {
            $result = $client->setServerConfig($config);

            return $result && ($result['code'] ?? -1) === 0;
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to set ZLM config', [
                'host' => $serverConfig['host'] ?? '',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function restart(array $serverConfig): bool
    {
        $client = $this->getClient($serverConfig);

        try {
            $result = $client->restartServer();

            return $result && ($result['code'] ?? -1) === 0;
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to restart ZLM server', [
                'host' => $serverConfig['host'] ?? '',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOnline(array $serverConfig): bool
    {
        $client = $this->getClient($serverConfig);

        try {
            $config = $client->getServerConfig();

            return !empty($config);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(array $serverConfig): ?array
    {
        $client = $this->getClient($serverConfig);

        try {
            return $client->getVersion();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取或创建 ZLM 客户端
     *
     * @param array $serverConfig
     * @return ZLMClient
     */
    private function getClient(array $serverConfig): ZLMClient
    {
        if ($this->client === null) {
            $this->client = new ZLMClient([
                'host' => $serverConfig['host'],
                'port' => $serverConfig['port'],
                'secret' => $serverConfig['secret'] ?? '',
                'debug' => $serverConfig['debug'] ?? false,
            ]);
        }

        return $this->client;
    }
}
