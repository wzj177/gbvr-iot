<?php

namespace CoreW\Business\Subscribe\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Subscribe\Service\SubscribeService;
use support\Log;

/**
 * 订阅续期任务
 *
 * 每10分钟执行一次，续订即将过期的订阅
 */
class SubscriptionRenewTask extends BaseCrontabTask
{
    public function execute(): void
    {
        $this->log()->info('SubscriptionRenewTask started');

        try {
            // 获取5分钟后过期的时间
            $expireTime = date('Y-m-d H:i:s', time() + 300);

            /** @var SubscribeService $subscribeService */
            $subscribeService = $this->getBfw()->service('Subscribe:SubscribeService');

            // 续订即将过期的订阅
            $count = $subscribeService->renewExpiringSubscriptions($expireTime);

            $this->log()->info('SubscriptionRenewTask completed', [
                'renewed_count' => $count,
                'expire_time' => $expireTime,
            ]);

        } catch (\Exception $e) {
            $this->log()->error('SubscriptionRenewTask failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
