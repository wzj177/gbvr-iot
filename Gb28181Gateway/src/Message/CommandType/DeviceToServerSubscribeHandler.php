<?php

namespace Gb28181Gateway\src\Message\CommandType;

/**
 * @deprecated
 */
class DeviceToServerSubscribeHandler
{
    /**
     * 处理移动设备位置订阅（SUBSCRIBE）
     *
     * 订阅流程：
     * 1. 平台发送 SUBSCRIBE（Event: presence, Expires: 3600）
     * 2. 检查 Expires 值（需要 > 0 且 < 3600）
     * 3. 如果 Expires 太小，返回 423 Interval Too Small + Min-Expires
     * 4. 保存订阅信息（设备ID、过期时间、CallID等）
     * 5. 返回 200 OK，等待设备发送 NOTIFY
     *
     * @param \SipEvent $event SUBSCRIBE 事件
     * @param string $deviceId 设备ID
     * @param int $expires 订阅时长（秒）
     * @param string $body 消息体（可能包含订阅参数）
     */
    public function handleMobilePositionSubscribe(\SipEvent $event, string $deviceId, int $expires, string $body): void
    {
        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            $this->log("设备未注册: {$deviceId}", 'WARNING');
            return;
        }

        $callId = $event->getCallId();
        $minExpires = $this->config['mobile_position_min_expires'] ?? 60; // 最小订阅时间（秒）
        $maxExpires = $this->config['mobile_position_max_expires'] ?? 3600; // 最大订阅时间（秒）

        $this->log("位置订阅请求: {$deviceId}, Expires: {$expires}");

        // 取消订阅（Expires = 0）
        if ($expires === 0) {
            $this->log("取消位置订阅: {$deviceId}");

            // 删除订阅记录
            $this->deviceManager->removeSubscription($deviceId, 'mobile_position');

            // 通知业务系统
            $this->postTask('mobile_position_unsubscribe', [
                'device_id' => $deviceId,
                'call_id' => $callId,
                'timestamp' => time(),
            ]);

            $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                'Expires' => 0
            ]);
            return;
        }

        // 检查订阅时间是否太小
        if ($expires > 0 && $expires < $minExpires) {
            $this->log("订阅时间太短: {$expires}s < {$minExpires}s (最小值)", 'WARNING');

            $this->sipServer->sendResponse($event->getTid(), 423, 'Interval Too Small', [
                'Min-Expires' => $minExpires
            ]);
            return;
        }

        // 限制最大订阅时间
        if ($expires > $maxExpires) {
            $expires = $maxExpires;
            $this->log("订阅时间超过最大值，限制为: {$maxExpires}s", 'WARNING');
        }

        // 解析订阅参数（如果有 XML Body）
        $interval = null; // 位置上报间隔
        if (!empty($body)) {
            $body = $this->normalizeXmlEncoding($body, $device->charset);
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $interval = isset($xml->Interval) ? (int)$xml->Interval : null;
            }
        }

        // 保存订阅信息
        $subscription = [
            'device_id' => $deviceId,
            'type' => 'mobile_position',
            'event' => 'presence',
            'call_id' => $callId,
            'expires' => $expires,
            'expire_time' => time() + $expires,
            'interval' => $interval,
            'created_at' => time(),
        ];

        $this->deviceManager->addSubscription($deviceId, 'mobile_position', $subscription);

        // 通知业务系统
        $this->postTask('mobile_position_subscribe', [
            'device_id' => $deviceId,
            'expires' => $expires,
            'interval' => $interval,
            'call_id' => $callId,
            'timestamp' => time(),
        ]);

        // 返回 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
            'Expires' => $expires
        ]);

        $this->log("位置订阅成功: {$deviceId}, 有效期: {$expires}s" . ($interval ? ", 上报间隔: {$interval}s" : ""));
    }

    /**
     * 处理目录变更订阅请求（SUBSCRIBE with Event: Catalog）
     *
     * 场景：下级平台/设备订阅本平台的目录变更
     *
     * @param \SipEvent $event SUBSCRIBE 事件
     * @param string $deviceId 设备ID
     * @param int $expires 订阅时长（秒）
     * @param string $body 消息体
     */
    public function handleCatalogSubscribe(\SipEvent $event, string $deviceId, int $expires, string $body): void
    {
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();
        $minExpires = $this->config['catalog_min_expires'] ?? 60;
        $maxExpires = $this->config['catalog_max_expires'] ?? 86400;

        $this->log("目录订阅请求: {$deviceId}, Expires: {$expires}");

        // 检查订阅时间范围
        if ($expires > 0 && $expires < $minExpires) {
            $this->log("订阅时间太短: {$expires}s < {$minExpires}s", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 423, 'Interval Too Small', [
                'Min-Expires' => $minExpires
            ]);
            return;
        }

        if ($expires > $maxExpires) {
            $expires = $maxExpires;
            $this->log("订阅时间超过最大值，限制为: {$maxExpires}s", 'WARNING');
        }

        // 保存订阅信息
        $subscription = [
            'device_id' => $deviceId,
            'type' => 'catalog',
            'event' => 'Catalog',
            'call_id' => $callId,
            'dialog_id' => $dialogId,
            'expires' => $expires,
            'expire_time' => time() + $expires,
            'created_at' => time(),
        ];

        $this->deviceManager->addSubscription($deviceId, 'catalog', $subscription);

        // 通知业务系统
        $this->postTask('catalog_subscribe', [
            'device_id' => $deviceId,
            'expires' => $expires,
            'call_id' => $callId,
            'dialog_id' => $dialogId,
            'timestamp' => time(),
        ]);

        // 返回 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
            'Expires' => $expires
        ]);

        $this->log("✓ 目录订阅成功: {$deviceId}, 有效期: {$expires}s");
    }

    /**
     * 处理报警事件订阅请求（SUBSCRIBE with Event: Alarm）
     *
     * 场景：下级平台/设备订阅本平台的报警推送
     *
     * @param \SipEvent $event SUBSCRIBE 事件
     * @param string $deviceId 设备ID
     * @param int $expires 订阅时长（秒）
     * @param string $body 消息体
     */
    public function handleAlarmSubscribe(\SipEvent $event, string $deviceId, int $expires, string $body): void
    {
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();
        $minExpires = $this->config['alarm_min_expires'] ?? 60;
        $maxExpires = $this->config['alarm_max_expires'] ?? 86400;

        $this->log("报警订阅请求: {$deviceId}, Expires: {$expires}");

        // 检查订阅时间范围
        if ($expires > 0 && $expires < $minExpires) {
            $this->log("订阅时间太短: {$expires}s < {$minExpires}s", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 423, 'Interval Too Small', [
                'Min-Expires' => $minExpires
            ]);
            return;
        }

        if ($expires > $maxExpires) {
            $expires = $maxExpires;
            $this->log("订阅时间超过最大值，限制为: {$maxExpires}s", 'WARNING');
        }

        // 解析订阅参数（如果有 XML Body）
        $alarmPriority = null;  // 可订阅指定优先级的报警
        $alarmMethod = null;    // 可订阅指定类型的报警

        if (!empty($body)) {
            $device = $this->deviceManager->getDeviceObject($deviceId);
            $body = $this->normalizeXmlEncoding($body, $device->charset ?? 'UTF-8');
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $alarmPriority = isset($xml->AlarmPriority) ? (string)$xml->AlarmPriority : null;
                $alarmMethod = isset($xml->AlarmMethod) ? (string)$xml->AlarmMethod : null;
            }
        }

        // 保存订阅信息
        $subscription = [
            'device_id' => $deviceId,
            'type' => 'alarm',
            'event' => 'Alarm',
            'call_id' => $callId,
            'dialog_id' => $dialogId,
            'expires' => $expires,
            'expire_time' => time() + $expires,
            'alarm_priority' => $alarmPriority,
            'alarm_method' => $alarmMethod,
            'created_at' => time(),
        ];

        $this->deviceManager->addSubscription($deviceId, 'alarm', $subscription);

        // 通知业务系统
        $this->postTask('alarm_subscribe', [
            'device_id' => $deviceId,
            'expires' => $expires,
            'alarm_priority' => $alarmPriority,
            'alarm_method' => $alarmMethod,
            'call_id' => $callId,
            'dialog_id' => $dialogId,
            'timestamp' => time(),
        ]);

        // 返回 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
            'Expires' => $expires
        ]);

        $this->log("✓ 报警订阅成功: {$deviceId}, 有效期: {$expires}s" .
            ($alarmPriority ? ", 优先级: {$alarmPriority}" : "") .
            ($alarmMethod ? ", 类型: {$alarmMethod}" : ""));
    }

    /**
     * 发送移动位置 NOTIFY 通知
     *
     * 当平台侧接收到位置更新时，通知订阅者。
     *
     * @param string $deviceId 设备 ID
     * @param array $position 位置信息 [time, longitude, latitude, speed, direction, altitude]
     * @return bool
     */
    public function notifyMobilePosition(string $deviceId, array $position): bool
    {
        $subscriptionManager = $this->getSubscriptionManager();
        if (!$subscriptionManager) {
            $this->log("发送位置 NOTIFY 失败：SubscriptionManager 未初始化", 'ERROR');
            return false;
        }

        // 构建 XML 消息体
        $xmlBody = $subscriptionManager->buildMobilePositionNotifyXml($deviceId, $position);

        // 发送 NOTIFY
        $result = $subscriptionManager->sendNotify($deviceId, 'MobilePosition', $xmlBody);

        if ($result) {
            $this->log("位置 NOTIFY 发送成功: device={$deviceId}, lon=" . ($position['longitude'] ?? '0') .
                ", lat=" . ($position['latitude'] ?? '0'), 'DEBUG');
        }

        return $result;
    }

    /**
     * 向所有订阅者广播 NOTIFY（目录变更等场景）
     *
     * @param string $eventType 事件类型
     * @param string $xmlBody XML 消息体
     * @return array ['sent' => int, 'failed' => int]
     */
    public function broadcastNotify(string $eventType, string $xmlBody): array
    {
        $subscriptionManager = $this->getSubscriptionManager();
        if (!$subscriptionManager) {
            $this->log("广播 NOTIFY 失败：SubscriptionManager 未初始化", 'ERROR');
            return ['sent' => 0, 'failed' => 0];
        }

        $result = $subscriptionManager->broadcastNotify($eventType, $xmlBody);

        $this->log("广播 NOTIFY 完成: type={$eventType}, sent={$result['sent']}, failed={$result['failed']}");

        return $result;
    }


    /**
     * 发送报警 NOTIFY 通知
     *
     * 当平台侧检测到报警事件时，通知订阅者。
     *
     * @param string $deviceId 设备 ID
     * @param array $alarmInfo 报警信息 [priority, method, type, time, description, longitude, latitude]
     * @return bool
     */
    public function notifyAlarm(string $deviceId, array $alarmInfo): bool
    {
        $subscriptionManager = $this->getSubscriptionManager();
        if (!$subscriptionManager) {
            $this->log("发送报警 NOTIFY 失败：SubscriptionManager 未初始化", 'ERROR');
            return false;
        }

        // 构建 XML 消息体
        $xmlBody = $subscriptionManager->buildAlarmNotifyXml($deviceId, $alarmInfo);

        // 发送 NOTIFY
        $result = $subscriptionManager->sendNotify($deviceId, 'Alarm', $xmlBody);

        if ($result) {
            $this->log("报警 NOTIFY 发送成功: device={$deviceId}, priority=" . ($alarmInfo['priority'] ?? '1'));
        }

        return $result;
    }

    /**
     * 获取订阅管理器实例
     *
     */
    private function getSubscriptionManager()
    {
        // 或者从配置中获取共享实例（需要在初始化时设置）
        if (isset($this->config['subscription_manager'])) {
            return $this->config['subscription_manager'];
        }

        return null;
    }


    /**
     * 发送目录变更 NOTIFY 通知
     *
     * 当平台侧设备目录发生变化时（增/删/改/上下线），通知订阅者。
     *
     * @param string $deviceId 设备 ID
     * @param array $channels 变更的通道列表
     * @param string $event 事件类型: ON/OFF/VLOST/DEFECT/ADD/DEL/UPDATE
     * @return bool
     */
    public function notifyCatalogChange(string $deviceId, array $channels, string $event = 'UPDATE'): bool
    {
        $subscriptionManager = $this->getSubscriptionManager();
        if (!$subscriptionManager) {
            $this->log("发送目录变更 NOTIFY 失败：SubscriptionManager 未初始化", 'ERROR');
            return false;
        }

        // 构建 XML 消息体
        $xmlBody = $subscriptionManager->buildCatalogNotifyXml($deviceId, $channels, $event);

        // 发送 NOTIFY
        $result = $subscriptionManager->sendNotify($deviceId, 'Catalog', $xmlBody);

        if ($result) {
            $this->log("目录变更 NOTIFY 发送成功: device={$deviceId}, event={$event}, channels=" . count($channels));
        }

        return $result;
    }

}