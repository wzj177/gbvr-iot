<?php

namespace CoreW\Business\MediaServer\Strategy;

use support\Log;

/**
 * SRS 流媒体服务器策略实现
 *
 * 注意：SRS暂不支持通过HTTP API动态添加/删除流代理
 * - 支持的功能：查询流状态、检查流是否在线
 * - 不支持的功能：动态添加/删除流代理（需要配置文件+reload）
 */
class SRSStrategy implements MediaServerStrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function getStats(array $serverConfig): array
    {
        try {
            $baseUrl = "http://{$serverConfig['host']}:{$serverConfig['port']}";

            // 获取系统摘要
            $summaries = $this->request($baseUrl, '/api/v1/summaries');

            // 获取流列表
            $streams = $this->request($baseUrl, '/api/v1/streams');

            // 获取客户端列表
            $clients = $this->request($baseUrl, '/api/v1/clients');

            return [
                'running' => !empty($summaries),
                'status' => !empty($summaries) ? 'running' : 'stopped',
                'version' => $summaries['data']['version'] ?? '',

                'snapshot' => [
                    'stream_count' => count($streams['streams'] ?? []),
                    'total_connection_count' => count($clients['clients'] ?? []),
                    'bytes_speed' => 0, // SRS不直接提供
                ],

                'summaries' => $summaries['data'] ?? [],
                'streams' => $streams['streams'] ?? [],
                'clients' => $clients['clients'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to get SRS stats', [
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
        // SRS 不支持通过HTTP API获取配置
        throw new \RuntimeException('SRS does not support getting config via HTTP API');
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(array $serverConfig, array $config): bool
    {
        // SRS 不支持通过HTTP API设置配置
        throw new \RuntimeException('SRS does not support setting config via HTTP API');
    }

    /**
     * {@inheritdoc}
     */
    public function restart(array $serverConfig): bool
    {
        // SRS 支持reload配置
        try {
            $baseUrl = "http://{$serverConfig['host']}:{$serverConfig['port']}";
            $result = $this->request($baseUrl, '/api/v1/raw?rpc=reload');

            return isset($result['code']) && $result['code'] == 0;
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to reload SRS config', [
                'host' => $serverConfig['host'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOnline(array $serverConfig): bool
    {
        try {
            $baseUrl = "http://{$serverConfig['host']}:{$serverConfig['port']}";
            $result = $this->request($baseUrl, '/api/v1/summaries');

            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(array $serverConfig): ?array
    {
        try {
            $baseUrl = "http://{$serverConfig['host']}:{$serverConfig['port']}";
            $result = $this->request($baseUrl, '/api/v1/versions');

            if ($result && isset($result['data'])) {
                return [
                    'version' => $result['data']['major'] . '.' . $result['data']['minor'] . '.' . $result['data']['revision'],
                    'major' => $result['data']['major'],
                    'minor' => $result['data']['minor'],
                    'revision' => $result['data']['revision'],
                    'build' => $result['data']['build'] ?? '',
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * 注意：SRS不支持通过HTTP API动态添加流代理
     * 流代理需要在配置文件中配置ingest，然后调用reload
     */
    public function addStreamProxy(array $serverConfig, array $proxyConfig): array
    {
        return [
            'success' => false,
            'key' => '',
            'message' => 'SRS does not support adding stream proxy via HTTP API. Please use ingest configuration in config file and reload.',
        ];
    }

    /**
     * {@inheritdoc}
     *
     * 注意：SRS不支持通过HTTP API删除流代理
     */
    public function delStreamProxy(array $serverConfig, string $key): bool
    {
        // SRS不支持动态删除流代理
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isStreamOnline(array $serverConfig, string $app, string $stream, string $vhost = '__defaultVhost__'): bool
    {
        try {
            $baseUrl = "http://{$serverConfig['host']}:{$serverConfig['port']}";
            $result = $this->request($baseUrl, '/api/v1/streams');

            if (isset($result['streams'])) {
                foreach ($result['streams'] as $s) {
                    // SRS streams格式: {vhost}/{app}/{stream}
                    $streamName = $s['name'] ?? '';
                    $expectedName = "{$vhost}/{$app}/{$stream}";

                    if ($streamName === $expectedName ||
                        strpos($streamName, "/{$app}/{$stream}") !== false) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('zlm')->error('Failed to check SRS stream status', [
                'host' => $serverConfig['host'] ?? '',
                'app' => $app,
                'stream' => $stream,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 发送HTTP请求到SRS
     */
    private function request(string $baseUrl, string $path): ?array
    {
        $url = $baseUrl . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new \RuntimeException("HTTP request failed: {$error}");
        }

        return json_decode($response, true);
    }
}
