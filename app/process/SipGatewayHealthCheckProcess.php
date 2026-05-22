<?php

namespace app\process;

use CoreW\Business\SipGateway\Service\SipGatewayService;
use CoreW\Core;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * SIP网关健康检查进程
 *
 * 每60秒检查一次所有active状态的网关
 * 如果 last_seen_at 超过 90 秒未更新，标记为 inactive
 */
class SipGatewayHealthCheckProcess
{
    public function onWorkerStart(Worker $worker) : void
    {
        // 延迟10秒启动，避免启动时与其他进程冲突
        Timer::add(10, function () {
            $this->checkGateways();
        }, [], false);

        // 每60秒执行一次健康检查
        Timer::add(60, function () {
            try {
                $this->checkGateways();
            } catch (\Throwable $e) {
                Log::channel('default')->error('[SipGatewayHealthCheck] Exception: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        Log::channel('default')->info('SipGatewayHealthCheckProcess started', [
            'worker_id' => $worker->id,
        ]);
    }

    protected function checkGateways() : void
    {
        $startTime = microtime(true);

        $offlineGateways = $this->getSipGatewayService()->checkOfflineGateways();

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        if (!empty($offlineGateways)) {
            Log::channel('default')->info('[SipGatewayHealthCheck] Offline gateways detected', [
                'gateways'   => $offlineGateways,
                'elapsed_ms' => $elapsed,
            ]);
        }
    }

    protected function getBfw() : \CoreW\Bfw
    {
        return Core::instance();
    }

    protected function getSipGatewayService() : SipGatewayService
    {
        return $this->getBfw()->service('SipGateway:SipGatewayService');
    }
}
