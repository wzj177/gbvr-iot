<?php

namespace app\process;

use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Core;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 流代理自动重连进程
 *
 * 功能：
 * - 每60秒检查一次offline/error状态的流代理
 * - 检查是否启用auto_reconnect且未超过max_retry_count
 * - 尝试重新启动流代理
 * - 更新重试次数和重连统计
 */
class StreamProxyAutoReconnectProcess
{
    public function onWorkerStart(Worker $worker) : void
    {
        // 检查是否启用
        if (!((int)env('ENABLE_STREAM_PROXY_AUTO_RECONNECT') ? : 1)) {
            Log::channel('stream_proxy')->info('StreamProxyAutoReconnectProcess disabled');
            return;
        }

        // 延迟10秒启动，避免与健康检查冲突
        Timer::add(10, function () use ($worker) {
            $this->runAutoReconnect();
        }, [], false);

        // 每60秒执行一次自动重连
        Timer::add(60, function () use ($worker) {
            try {
                $this->runAutoReconnect();
            } catch (\Throwable $e) {
                Log::channel('stream_proxy')->error('[AutoReconnect] Exception: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        Log::channel('stream_proxy')->info('StreamProxyAutoReconnectProcess started', [
            'worker_id' => $worker->id,
        ]);
    }

    protected function runAutoReconnect() : void
    {
        $startTime = microtime(true);

        $service = $this->getStreamProxyService();

        // 批量自动重连
        $result = $service->autoReconnect();

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        if ($result['total'] > 0) {
            Log::channel('stream_proxy')->info('[AutoReconnect] Completed', [
                'total'      => $result['total'],
                'success'    => $result['success'],
                'failed'     => $result['failed'],
                'skipped'    => $result['skipped'],
                'elapsed_ms' => $elapsed,
            ]);
        }
    }

    protected function getBfw() : \CoreW\Bfw
    {
        return Core::instance();
    }

    protected function getStreamProxyService() : StreamProxyService
    {
        return $this->getBfw()->service('StreamProxy:StreamProxyService');
    }
}
