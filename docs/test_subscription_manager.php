<?php
/**
 * 订阅管理器测试示例
 * 
 * 演示：
 * 1. PHP 层管理订阅状态（Redis 存储，无上限）
 * 2. C 层仅负责 SIP 协议操作
 * 3. 自动刷新机制
 * 4. 统计和监控
 */

require_once __DIR__ . '/vendor/autoload.php';

use Gb28181\GateWay\Device\Subscribe\SubscriptionManager;
use Gb28181\GateWay\Device\Device;
use Gb28181\GateWay\Libs\Logger;

// 创建 SIP 服务器
$sipServer = new ExoSip([
    'ip' => '0.0.0.0',
    'port' => 15060,
    'mode' => 'udp',
    'sipId' => '34020000002000000001',
    'sipRealm' => '3402000000',
    'debug' => true,
]);

// 创建 Redis 连接
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

// 创建订阅管理器
$subscriptionManager = new SubscriptionManager($sipServer, $redis, [
    'auto_refresh' => true,
    'refresh_advance' => 300,  // 提前 5 分钟刷新
    'debug' => true,
]);

echo "==============================================\n";
echo "  订阅管理器测试 - PHP 层无限扩展方案\n";
echo "==============================================\n\n";

// 模拟设备对象
$device = new Device([
    'deviceId' => '34020000001320000001',
    'ip' => '192.168.1.100',
    'port' => 5060,
    'received_ip' => '192.168.1.100',  // 实际来源 IP
    'received_port' => 5060,
]);

// ==================== 1. 订阅操作 ====================
echo "[1] 订阅操作测试\n";
echo "----------------------------------------\n";

// 订阅目录变更
try {
    $result = $subscriptionManager->subscribe($device, 'Catalog', 3600);
    echo "✓ 订阅 Catalog 成功: subscription_id=" . $result['subscription_id'] . "\n";
} catch (Exception $e) {
    echo "✗ 订阅 Catalog 失败: " . $e->getMessage() . "\n";
}

// 订阅报警事件（带参数）
try {
    $result = $subscriptionManager->subscribe($device, 'Alarm', 3600, [
        'start_priority' => 1,
        'end_priority' => 3,
        'alarm_method' => '1',  // 电话报警
    ]);
    echo "✓ 订阅 Alarm 成功: subscription_id=" . $result['subscription_id'] . "\n";
} catch (Exception $e) {
    echo "✗ 订阅 Alarm 失败: " . $e->getMessage() . "\n";
}

// 订阅移动位置（带间隔参数）
try {
    $result = $subscriptionManager->subscribe($device, 'MobilePosition', 3600, [
        'interval' => 10,  // 每 10 秒上报一次
    ]);
    echo "✓ 订阅 MobilePosition 成功: subscription_id=" . $result['subscription_id'] . "\n";
} catch (Exception $e) {
    echo "✗ 订阅 MobilePosition 失败: " . $e->getMessage() . "\n";
}

echo "\n";

// ==================== 2. 查询订阅信息 ====================
echo "[2] 查询订阅信息\n";
echo "----------------------------------------\n";

// 查询单个订阅
$subscription = $subscriptionManager->getSubscription($device->deviceId, 'Catalog');
if (!empty($subscription)) {
    echo "设备 {$device->deviceId} 的 Catalog 订阅:\n";
    echo "  - subscription_id: " . $subscription['subscription_id'] . "\n";
    echo "  - expires: " . $subscription['expires'] . " 秒\n";
    echo "  - created_at: " . date('Y-m-d H:i:s', $subscription['created_at']) . "\n";
    echo "  - next_refresh: " . date('Y-m-d H:i:s', $subscription['next_refresh']) . "\n";
}

// 查询设备的所有订阅
$allSubscriptions = $subscriptionManager->getSubscription($device->deviceId);
echo "\n设备 {$device->deviceId} 的所有订阅:\n";
foreach ($allSubscriptions as $sub) {
    echo "  - {$sub['event_type']}: subscription_id={$sub['subscription_id']}, expires={$sub['expires']}s\n";
}

echo "\n";

// ==================== 3. 统计信息 ====================
echo "[3] 统计信息\n";
echo "----------------------------------------\n";

$stats = $subscriptionManager->getStatistics();
echo "总订阅数: " . $stats['total'] . "\n";
echo "刷新队列: " . $stats['refresh_queue_size'] . " 个待刷新\n";
echo "按类型统计:\n";
foreach ($stats['by_type'] as $type => $count) {
    echo "  - {$type}: {$count} 个\n";
}

echo "\n";

// ==================== 4. 模拟 200 OK 响应（更新 dialog_id） ====================
echo "[4] 模拟 SUBSCRIBE 200 OK 响应\n";
echo "----------------------------------------\n";

// 在实际应用中，这会在 onResponse 回调中调用
$catalogSub = $subscriptionManager->getSubscription($device->deviceId, 'Catalog');
if (!empty($catalogSub)) {
    $subscriptionManager->updateDialogId($catalogSub['subscription_id'], 12345);
    echo "✓ 更新 dialog_id: subscription_id={$catalogSub['subscription_id']}, dialog_id=12345\n";
}

echo "\n";

// ==================== 5. 自动刷新测试 ====================
echo "[5] 自动刷新测试\n";
echo "----------------------------------------\n";

// 手动触发刷新检查
$refreshResult = $subscriptionManager->autoRefreshAll();
echo "刷新结果:\n";
echo "  - 成功: {$refreshResult['refreshed']} 个\n";
echo "  - 失败: {$refreshResult['failed']} 个\n";
echo "  - 总计: {$refreshResult['total']} 个待刷新\n";

echo "\n";

// ==================== 6. 手动刷新单个订阅 ====================
echo "[6] 手动刷新单个订阅\n";
echo "----------------------------------------\n";

if ($subscriptionManager->refresh($device->deviceId, 'Catalog')) {
    echo "✓ 刷新 Catalog 订阅成功\n";
} else {
    echo "✗ 刷新 Catalog 订阅失败\n";
}

echo "\n";

// ==================== 7. 检查订阅是否存在 ====================
echo "[7] 检查订阅存在性\n";
echo "----------------------------------------\n";

if ($subscriptionManager->exists($device->deviceId, 'Catalog')) {
    echo "✓ Catalog 订阅存在\n";
} else {
    echo "✗ Catalog 订阅不存在\n";
}

if ($subscriptionManager->exists($device->deviceId, 'RecordInfo')) {
    echo "✓ RecordInfo 订阅存在\n";
} else {
    echo "✗ RecordInfo 订阅不存在（预期）\n";
}

echo "\n";

// ==================== 8. 取消单个订阅 ====================
echo "[8] 取消单个订阅\n";
echo "----------------------------------------\n";

if ($subscriptionManager->unsubscribe($device, 'MobilePosition')) {
    echo "✓ 取消 MobilePosition 订阅成功\n";
} else {
    echo "✗ 取消 MobilePosition 订阅失败\n";
}

echo "\n";

// ==================== 9. 清理过期订阅 ====================
echo "[9] 清理过期订阅\n";
echo "----------------------------------------\n";

$cleanupResult = $subscriptionManager->cleanupExpired();
echo "清理结果: {$cleanupResult['cleaned']} 个过期订阅被删除\n";

echo "\n";

// ==================== 10. 取消设备所有订阅 ====================
echo "[10] 取消设备所有订阅\n";
echo "----------------------------------------\n";

$unsubscribeResult = $subscriptionManager->unsubscribeAll($device);
echo "取消结果:\n";
echo "  - 成功: {$unsubscribeResult['canceled']} 个\n";
echo "  - 失败: {$unsubscribeResult['failed']} 个\n";
echo "  - 总计: {$unsubscribeResult['total']} 个订阅\n";

echo "\n";

// ==================== 11. 最终统计 ====================
echo "[11] 最终统计\n";
echo "----------------------------------------\n";

$finalStats = $subscriptionManager->getStatistics();
echo "总订阅数: " . $finalStats['total'] . "\n";
echo "刷新队列: " . $finalStats['refresh_queue_size'] . " 个待刷新\n";

echo "\n";
echo "==============================================\n";
echo "测试完成！\n";
echo "\n";
echo "优势总结:\n";
echo "  ✓ 无订阅数量限制（Redis 支持上万设备）\n";
echo "  ✓ 持久化（进程重启不丢失）\n";
echo "  ✓ 分布式（多节点共享订阅状态）\n";
echo "  ✓ 高效查询（O(1) 哈希查找）\n";
echo "  ✓ 自动刷新（定时器驱动）\n";wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww
echo "==============================================\n";
