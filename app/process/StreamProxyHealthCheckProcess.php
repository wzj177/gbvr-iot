<?php

namespace app\process;

use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Core;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 流代理健康检查进程
 *
 * 功能：
 * - 每30秒检查一次所有在线状态的流代理
 * - 调用ZLM API检查流是否真实存在
 * - 流在线：更新last_heartbeat_at
 * - 流离线：更新status='offline'
 */
class StreamProxyHealthCheckProcess
{
    public function onWorkerStart(Worker $worker): void
    {
        // 检查是否启用
        if (!((int)env('ENABLE_STREAM_PROXY_HEALTH_CHECK') ?: 1)) {
            Log::channel('stream_proxy')->info('StreamProxyHealthCheckProcess disabled');
            return;
        }

        // 延迟5秒启动，避免启动时与其他进程冲突
        Timer::add(5, function () use ($worker) {
            $this->runHealthCheck();
        }, [], false);

        // 每30秒执行一次健康检查
        Timer::add(30, function () use ($worker) {
            try {
                $this->runHealthCheck();
            } catch (\Throwable $e) {
                Log::channel('stream_proxy')->error('[HealthCheck] Exception: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        Log::channel('stream_proxy')->info('StreamProxyHealthCheckProcess started', [
            'worker_id' => $worker->id,
        ]);
    }

    protected function runHealthCheck(): void
    {
        $startTime = microtime(true);

        $service = $this->getStreamProxyService();

        // 批量健康检查
        $result = $service->batchHealthCheck();

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        Log::channel('stream_proxy')->info('[HealthCheck] Completed', [
            'total' => $result['total'],
            'online' => $result['online'],
            'offline' => $result['offline'],
            'elapsed_ms' => $elapsed,
        ]);
    }

    protected function getBfw(): \CoreW\Bfw
    {
        return Core::instance();
    }

    protected function getStreamProxyService(): StreamProxyService
    {
        return $this->getBfw()->service('StreamProxy:StreamProxyService');
    }
}
