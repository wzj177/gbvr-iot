<?php
/**
 * GB28181Handler 与 SubscriptionManager 集成示例
 * 
 * 展示如何在 GB28181 网关中使用订阅管理器
 */

require_once __DIR__ . '/vendor/autoload.php';

use Gb28181\GateWay\Device\Subscribe\SubscriptionManager;
use Gb28181\GateWay\Protocol\GB28181Handler;
use Gb28181\GateWay\Device\DeviceManager;

// 创建 SIP 服务器
$sipServer = new ExoSip([
    'ip' => '0.0.0.0',
    'port' => 15060,
    'mode' => 'udp',
    'sipId' => '34020000002000000001',
    'sipRealm' => '3402000000',
    'debug' => true,
    'task_worker_num' => 4,        // 4 个 Task 进程
    'timer_interval' => 30000,     // 30 秒定时器
]);

// 创建 Redis 连接
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// 创建设备管理器
$deviceManager = new DeviceManager($sipServer, $redis);

// 创建订阅管理器
$subscriptionManager = new SubscriptionManager($sipServer, $redis, [
    'auto_refresh' => true,
    'refresh_advance' => 300,  // 提前 5 分钟刷新
    'debug' => true,
]);

// 创建 GB28181 协议处理器
$gb28181 = new GB28181Handler($sipServer, [
    'server_id' => '34020000002000000001',
    'server_domain' => '3402000000',
    'debug' => true,
]);

echo "==============================================\n";
echo "  GB28181 网关 - 订阅管理集成示例\n";
echo "==============================================\n\n";

// ==================== 1. 设备注册处理 ====================
$sipServer->onRegister = function($event) use ($sipServer, $gb28181, $deviceManager, $subscriptionManager) {
    $deviceId = extractDeviceId($event->getFromUri());
    
    echo "[REGISTER] 设备 {$deviceId} 注册\n";
    
    // 1. 处理注册（标准 GB28181 流程）
    $result = $gb28181->handleRegister($event);
    
    if ($result['success']) {
        // 2. 获取设备对象
        $device = $deviceManager->getDevice($deviceId);
        
        // 3. 自动订阅设备目录（注册成功后）
        if ($device) {
            try {
                $subResult = $subscriptionManager->subscribe($device, 'Catalog', 3600);
                echo "[SUBSCRIBE] 自动订阅 Catalog: subscription_id=" . $subResult['subscription_id'] . "\n";
            } catch (Exception $e) {
                echo "[ERROR] 订阅失败: " . $e->getMessage() . "\n";
            }
        }
    }
};

// ==================== 2. SUBSCRIBE 响应处理（更新 dialog_id） ====================
$sipServer->onResponse = function($event) use ($subscriptionManager) {
    $code = $event->getCode();
    
    // 处理 SUBSCRIBE 200 OK 响应
    if ($code == 200) {
        $session = $event->getSession();
        if ($session) {
            $subscriptionId = $session->getCallId();  // subscription_id == call_id
            $dialogId = $session->getDialogId();
            
            // 更新 Redis 中的 dialog_id
            $subscriptionManager->updateDialogId($subscriptionId, $dialogId);
            
            echo "[RESPONSE] 更新 dialog_id: subscription_id={$subscriptionId}, dialog_id={$dialogId}\n";
        }
    }
};

// ==================== 3. NOTIFY 事件处理 ====================
$sipServer->onNotify = function($event) use ($sipServer, $deviceManager) {
    $deviceId = extractDeviceId($event->getFromUri());
    $body = $event->getBody();
    
    echo "[NOTIFY] 收到通知: device={$deviceId}\n";
    
    // 解析 XML
    $xml = simplexml_load_string($body);
    $cmdType = (string)$xml->CmdType;
    
    echo "[NOTIFY] 事件类型: {$cmdType}\n";
    
    // 处理不同类型的 NOTIFY
    switch ($cmdType) {
        case 'Catalog':
            echo "[NOTIFY] 设备目录变更通知\n";
            echo "  - SumNum: " . (string)$xml->SumNum . "\n";
            
            // 解析目录项
            if (isset($xml->DeviceList->Item)) {
                foreach ($xml->DeviceList->Item as $item) {
                    echo "  - 通道: " . (string)$item->DeviceID . " - " . (string)$item->Name . "\n";
                }
            }
            break;
            
        case 'Alarm':
            echo "[NOTIFY] 报警事件通知\n";
            echo "  - AlarmPriority: " . (string)$xml->AlarmPriority . "\n";
            echo "  - AlarmMethod: " . (string)$xml->AlarmMethod . "\n";
            echo "  - AlarmTime: " . (string)$xml->AlarmTime . "\n";
            break;
            
        case 'MobilePosition':
            echo "[NOTIFY] 移动位置上报\n";
            echo "  - Longitude: " . (string)$xml->Longitude . "\n";
            echo "  - Latitude: " . (string)$xml->Latitude . "\n";
            echo "  - Time: " . (string)$xml->Time . "\n";
            break;
            
        default:
            echo "[NOTIFY] 未知通知类型: {$cmdType}\n";
    }
    
    // 响应 200 OK
    $sipServer->sendNotifyResponse($event->getTid(), 200);
};

// ==================== 4. 定时器：自动刷新订阅 ====================
$sipServer->onTimer = function() use ($subscriptionManager, $deviceManager) {
    static $timerCount = 0;
    $timerCount++;
    
    echo "[TIMER] 定时任务 #{$timerCount}\n";
    
    // 每次定时器触发时自动刷新所有需要续订的订阅
    $refreshResult = $subscriptionManager->autoRefreshAll();
    
    if ($refreshResult['total'] > 0) {
        echo "[TIMER] 刷新订阅: 成功={$refreshResult['refreshed']}, 失败={$refreshResult['failed']}, 总计={$refreshResult['total']}\n";
    }
    
    // 每 10 次清理一次过期订阅（5 分钟一次）
    if ($timerCount % 10 === 0) {
        $cleanupResult = $subscriptionManager->cleanupExpired();
        if ($cleanupResult['cleaned'] > 0) {
            echo "[TIMER] 清理过期订阅: {$cleanupResult['cleaned']} 个\n";
        }
    }
    
    // 打印统计信息
    $stats = $subscriptionManager->getStatistics();
    echo "[TIMER] 订阅统计: 总计={$stats['total']}, 刷新队列={$stats['refresh_queue_size']}\n";
    
    // 打印设备统计
    $deviceStats = $deviceManager->getStatistics();
    echo "[TIMER] 设备统计: 在线={$deviceStats['online']}, 离线={$deviceStats['offline']}, 总计={$deviceStats['total']}\n";
    
    echo "\n";
};

// ==================== 5. Task 进程处理（异步任务） ====================
$sipServer->onTask = function($server, $taskId, $data) use ($subscriptionManager, $deviceManager) {
    $action = $data['action'] ?? 'unknown';
    
    echo "[TASK-{$taskId}] 处理任务: {$action}\n";
    
    switch ($action) {
        case 'batch_subscribe':
            // 批量订阅（从数据库读取设备列表）
            $deviceIds = $data['device_ids'] ?? [];
            $eventType = $data['event_type'] ?? 'Catalog';
            
            $success = 0;
            $failed = 0;
            
            foreach ($deviceIds as $deviceId) {
                $device = $deviceManager->getDevice($deviceId);
                if ($device && $device->isOnline()) {
                    try {
                        $subscriptionManager->subscribe($device, $eventType, 3600);
                        $success++;
                    } catch (Exception $e) {
                        $failed++;
                    }
                }
            }
            
            return [
                'action' => 'batch_subscribe',
                'success' => $success,
                'failed' => $failed,
                'total' => count($deviceIds)
            ];
            
        case 'export_subscriptions':
            // 导出订阅信息（生成报表）
            $subscriptions = $subscriptionManager->getAllSubscriptions(0, 1000);
            
            // 模拟生成 CSV 文件
            $csvData = "设备ID,事件类型,订阅ID,过期时间,下次刷新\n";
            foreach ($subscriptions as $sub) {
                $csvData .= "{$sub['device_id']},{$sub['event_type']},{$sub['subscription_id']},";
                $csvData .= date('Y-m-d H:i:s', $sub['created_at'] + $sub['expires']) . ",";
                $csvData .= date('Y-m-d H:i:s', $sub['next_refresh']) . "\n";
            }
            
            file_put_contents('/tmp/subscriptions_' . time() . '.csv', $csvData);
            
            return [
                'action' => 'export_subscriptions',
                'count' => count($subscriptions)
            ];
            
        default:
            return ['error' => 'Unknown action'];
    }
};

// ==================== 6. Task 完成回调 ====================
$sipServer->onTaskFinish = function($server, $taskId, $result) {
    echo "[TASK-FINISH] 任务 #{$taskId} 完成: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n\n";
};

// ==================== 7. 错误处理 ====================
$sipServer->onError = function($error) {
    echo "[ERROR] {$error}\n";
};

echo "[INFO] GB28181 网关启动中...\n";
echo "[INFO] - 订阅管理: PHP 层（Redis 存储）\n";
echo "[INFO] - 自动刷新: 提前 5 分钟续订\n";
echo "[INFO] - 定时器: 30 秒检查一次\n";
echo "[INFO] - Task 进程: 4 个异步任务处理\n";
echo "==============================================\n\n";

// 启动服务器
$sipServer->run();

echo "\n[INFO] GB28181 网关已停止\n";

// ==================== 辅助函数 ====================

function extractDeviceId(string $uri): string
{
    // 从 sip:34020000001320000001@192.168.1.100:5060 提取设备 ID
    if (preg_match('/sip:([^@]+)@/', $uri, $matches)) {
        return $matches[1];
    }
    return '';
}
